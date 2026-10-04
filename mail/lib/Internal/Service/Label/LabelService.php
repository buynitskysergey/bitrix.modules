<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Label;

use Bitrix\Mail\Helper\Label\LabelsFeature;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\MessageLabelTable;
use Bitrix\Mail\Internals\UserLabelTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class LabelService
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_NOT_FOUND = 'LABEL_NOT_FOUND';
	public const ERROR_MAILBOX_MISMATCH = 'LABEL_MAILBOX_MISMATCH';
	public const ERROR_NAME_EMPTY = 'LABEL_NAME_EMPTY';
	public const ERROR_NAME_NOT_UNIQUE = 'LABEL_NAME_NOT_UNIQUE';
	public const ERROR_LIMIT_EXCEEDED = 'LABEL_LIMIT_EXCEEDED';
	public const ERROR_BATCH_TOO_LARGE = 'LABEL_BATCH_TOO_LARGE';

	private const DEFAULT_LIMIT = 20;
	private const NAME_MAX_LENGTH = 128;

	/**
	 * Checked before any query: the write path materializes labels x messages per mailbox.
	 */
	private const MAX_LABELS_PER_REQUEST = 50;
	private const MAX_MESSAGES_PER_REQUEST = 500;
	private const MAX_MAILBOXES_PER_REQUEST = 50;
	private const BINDINGS_INSERT_CHUNK_SIZE = 1000;

	private ?LabelCountersService $countersService = null;

	/**
	 * Not gated on LabelsFeature: the flag guards writes and onboarding, not the own label listing.
	 * Unread counters are summed across the user's mailboxes.
	 */
	public function getList(int $userId, ?int $mailboxId = null): Result
	{
		$result = new Result();

		$filter = ['=USER_ID' => $userId];
		if ($mailboxId !== null)
		{
			$filter['@MAILBOX_ID'] = array_values(array_unique([0, $mailboxId]));
		}

		$rows = UserLabelTable::getList([
			'select' => ['ID', 'USER_ID', 'NAME', 'MAILBOX_ID', 'SORT'],
			'filter' => $filter,
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		$counters = $this->getCountersService()->getCountersForLabelIds(
			array_map(static fn (array $row): int => (int)$row['ID'], $rows),
		);

		$labels = [];
		foreach ($rows as $row)
		{
			$dto = $this->toDto($row);
			$dto['unread'] = $counters[(int)$row['ID']] ?? 0;
			$labels[] = $dto;
		}

		$result->setData(['labels' => $labels]);

		return $result;
	}

	/**
	 * Only the caller's own labels: a shared mailbox never exposes other users' bindings.
	 *
	 * @return int[]
	 */
	public function getMessageLabelIds(int $userId, int $mailboxId, int $messageId): array
	{
		if ($userId <= 0 || $mailboxId <= 0 || $messageId <= 0)
		{
			return [];
		}

		$boundLabelIds = array_map(
			static fn (array $row): int => (int)$row['LABEL_ID'],
			MessageLabelTable::getList([
				'select' => ['LABEL_ID'],
				'filter' => [
					'=MAILBOX_ID' => $mailboxId,
					'=MESSAGE_ID' => $messageId,
				],
			])->fetchAll(),
		);
		return $this->filterOwnLabelIds($userId, $boundLabelIds);
	}

	/**
	 * Label ids of the caller attached to every message of the set. The group selector shows this as
	 * its current state, so a partially present label starts unchecked.
	 *
	 * @param string[] $ids
	 * @return int[]
	 */
	public function getCommonMessageLabelIds(int $userId, array $ids): array
	{
		if ($userId <= 0 || $ids === [] || count($ids) > self::MAX_MESSAGES_PER_REQUEST)
		{
			return [];
		}

		$groups = $this->groupIdsByMailbox($ids);
		if (count($groups) > self::MAX_MAILBOXES_PER_REQUEST)
		{
			return [];
		}

		$ownLabelIds = $this->findLabelIds(['=USER_ID' => $userId]);
		if ($ownLabelIds === [])
		{
			return [];
		}

		$messagesTotal = 0;
		$countByLabel = [];

		foreach ($groups as $mailboxId => $group)
		{
			$mailboxId = (int)$mailboxId;
			// A foreign mailbox stays indistinguishable from a nonexistent one.
			if (!MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true))
			{
				continue;
			}

			$messageIds = $this->resolveMessageIds($mailboxId, $group['uidIds']);
			if ($messageIds === [])
			{
				continue;
			}

			$messagesTotal += count($messageIds);

			$rows = MessageLabelTable::getList([
				'select' => ['LABEL_ID'],
				'filter' => [
					'=MAILBOX_ID' => $mailboxId,
					'@MESSAGE_ID' => $messageIds,
					'@LABEL_ID' => $ownLabelIds,
				],
			]);
			while ($row = $rows->fetch())
			{
				$labelId = (int)$row['LABEL_ID'];
				$countByLabel[$labelId] = ($countByLabel[$labelId] ?? 0) + 1;
			}
		}

		if ($messagesTotal === 0)
		{
			return [];
		}

		return array_keys(array_filter(
			$countByLabel,
			static fn (int $count): bool => $count === $messagesTotal,
		));
	}

	/**
	 * @param int[] $labelIds
	 * @return int[]
	 */
	private function filterOwnLabelIds(int $userId, array $labelIds): array
	{
		if ($labelIds === [])
		{
			return [];
		}

		return array_map(
			static fn (array $row): int => (int)$row['ID'],
			UserLabelTable::getList([
				'select' => ['ID'],
				'filter' => [
					'=USER_ID' => $userId,
					'@ID' => $labelIds,
				],
			])->fetchAll(),
		);
	}

	/**
	 * @return int[]
	 */
	public function getMessageLabelIdsByCompositeId(int $userId, string $id): array
	{
		$grouped = $this->groupIdsByMailbox([$id]);
		if ($grouped === [])
		{
			return [];
		}

		$mailboxId = (int)array_key_first($grouped);
		// A foreign mailbox stays indistinguishable from a nonexistent one, as in the batch path.
		if (!MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true))
		{
			return [];
		}

		$messageIds = $this->resolveMessageIds($mailboxId, $grouped[$mailboxId]['uidIds']);
		if ($messageIds === [])
		{
			return [];
		}

		return $this->getMessageLabelIds($userId, $mailboxId, $messageIds[0]);
	}

	/**
	 * Idempotent; a label pinned to a mailbox is rejected for messages of any other mailbox.
	 *
	 * @param int[] $labelIds
	 * @param string[] $ids Composite "{uidId}-{mailboxId}" identifiers of mail.message.* actions.
	 */
	public function assign(int $userId, array $labelIds, array $ids): Result
	{
		return $this->applyBindings($userId, $labelIds, $ids, true);
	}

	/**
	 * @param int[] $labelIds
	 * @param string[] $ids
	 */
	public function unassign(int $userId, array $labelIds, array $ids): Result
	{
		return $this->applyBindings($userId, $labelIds, $ids, false);
	}

	/**
	 * Same checks as assign(), for in-module callers holding a stored b_mail_message.ID. Idempotent.
	 *
	 * @param int[] $labelIds
	 */
	public function assignToMessage(int $userId, int $mailboxId, int $messageId, array $labelIds): Result
	{
		return $this->writeSingleMessageBindings($userId, $mailboxId, $messageId, $labelIds, true);
	}

	/**
	 * @param int[] $labelIds
	 */
	public function unassignFromMessage(int $userId, int $mailboxId, int $messageId, array $labelIds): Result
	{
		return $this->writeSingleMessageBindings($userId, $mailboxId, $messageId, $labelIds, false);
	}

	/**
	 * $mailboxId 0 creates a global label, visible in every mailbox of the user.
	 */
	public function add(int $userId, string $name, int $mailboxId = 0): Result
	{
		$result = new Result();

		$name = $this->normalizeName($name);
		if ($name === '')
		{
			$result->addError($this->error(self::ERROR_NAME_EMPTY, 'Label name is empty'));

			return $result;
		}

		if ($mailboxId > 0 && !MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true))
		{
			$result->addError($this->error(self::ERROR_ACCESS_DENIED, 'Access denied'));

			return $result;
		}

		if ($this->isLimitReached($userId))
		{
			$result->addError($this->error(self::ERROR_LIMIT_EXCEEDED, 'Labels limit exceeded'));

			return $result;
		}

		if ($this->nameExists($userId, $mailboxId, $name))
		{
			$result->addError($this->error(self::ERROR_NAME_NOT_UNIQUE, 'Label name is not unique'));

			return $result;
		}

		try
		{
			$addResult = UserLabelTable::add([
				'USER_ID' => $userId,
				'MAILBOX_ID' => $mailboxId,
				'NAME' => $name,
				'DATE_INSERT' => new DateTime(),
			]);
		}
		catch (DuplicateEntryException)
		{
			$result->addError($this->error(self::ERROR_NAME_NOT_UNIQUE, 'Label name is not unique'));

			return $result;
		}

		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());

			return $result;
		}

		$result->setData(['label' => $this->getDto((int)$addResult->getId())]);

		return $result;
	}

	public function rename(int $userId, int $labelId, string $name): Result
	{
		$result = new Result();

		$label = $this->findLabelRow($labelId);
		$accessError = $this->checkOwnedLabel($label, $userId);
		if ($accessError !== null)
		{
			$result->addError($accessError);

			return $result;
		}

		$name = $this->normalizeName($name);
		if ($name === '')
		{
			$result->addError($this->error(self::ERROR_NAME_EMPTY, 'Label name is empty'));

			return $result;
		}

		if ($this->nameExists($userId, (int)$label['MAILBOX_ID'], $name, $labelId))
		{
			$result->addError($this->error(self::ERROR_NAME_NOT_UNIQUE, 'Label name is not unique'));

			return $result;
		}

		try
		{
			$updateResult = UserLabelTable::update($labelId, ['NAME' => $name]);
		}
		catch (DuplicateEntryException)
		{
			$result->addError($this->error(self::ERROR_NAME_NOT_UNIQUE, 'Label name is not unique'));

			return $result;
		}

		if (!$updateResult->isSuccess())
		{
			$result->addErrors($updateResult->getErrors());

			return $result;
		}

		$dto = $this->getDto($labelId);
		$dto['unread'] = $this->getCountersService()->getCountersForLabelIds([$labelId])[$labelId] ?? 0;

		$result->setData(['label' => $dto]);

		return $result;
	}

	public function delete(int $userId, int $labelId): Result
	{
		$result = new Result();

		$label = $this->findLabelRow($labelId);
		$accessError = $this->checkOwnedLabel($label, $userId);
		if ($accessError !== null)
		{
			$result->addError($accessError);

			return $result;
		}

		$this->deleteLabels([$labelId]);

		return $result;
	}

	/**
	 * Bindings of every user in the mailbox and labels pinned to it go away; global labels only lose
	 * their bindings. Must run before the mailbox counter rows are wiped: the push reads their diff.
	 */
	public function deleteByMailbox(int $mailboxId): void
	{
		if ($mailboxId <= 0 || !LabelsFeature::isEnabled())
		{
			return;
		}

		$boundLabelIds = $this->findBoundLabelIds($mailboxId);
		$globalLabelIds = $boundLabelIds === []
			? []
			: $this->findLabelIds(['@ID' => $boundLabelIds, '=MAILBOX_ID' => 0]);

		MessageLabelTable::deleteByFilter(['=MAILBOX_ID' => $mailboxId]);

		$this->deleteLabels($this->findLabelIds(['=MAILBOX_ID' => $mailboxId]));
		$this->recalculateCounters($mailboxId, $globalLabelIds);
	}

	public function deleteByUser(int $userId): void
	{
		if ($userId <= 0 || !LabelsFeature::isEnabled())
		{
			return;
		}

		$this->deleteLabels($this->findLabelIds(['=USER_ID' => $userId]));
	}

	/**
	 * Owner change: the previous owner loses his bindings here and his labels pinned to the mailbox.
	 * Bindings of other users in the same mailbox are untouched.
	 */
	public function detachUserFromMailbox(int $userId, int $mailboxId): void
	{
		if ($userId <= 0 || $mailboxId <= 0 || !LabelsFeature::isEnabled())
		{
			return;
		}

		$pinnedLabelIds = $this->findLabelIds(['=USER_ID' => $userId, '=MAILBOX_ID' => $mailboxId]);
		$globalLabelIds = $this->findLabelIds(['=USER_ID' => $userId, '=MAILBOX_ID' => 0]);

		// Read before the bindings go away: only labels that had one here need a recount.
		$boundLabelIds = $globalLabelIds === [] ? [] : $this->findBoundLabelIds($mailboxId);

		$ownLabelIds = array_merge($pinnedLabelIds, $globalLabelIds);
		if ($ownLabelIds !== [])
		{
			MessageLabelTable::deleteByFilter([
				'=MAILBOX_ID' => $mailboxId,
				'@LABEL_ID' => $ownLabelIds,
			]);
		}

		$this->deleteLabels($pinnedLabelIds);
		$this->recalculateCounters(
			$mailboxId,
			array_values(array_intersect($globalLabelIds, $boundLabelIds)),
		);
	}

	/**
	 * @param int[] $labelIds
	 */
	private function deleteLabels(array $labelIds): void
	{
		$labelIds = $this->normalizeLabelIds($labelIds);
		if ($labelIds === [])
		{
			return;
		}

		MessageLabelTable::deleteByFilter(['@LABEL_ID' => $labelIds]);
		$this->getCountersService()->deleteForLabels($labelIds);
		UserLabelTable::deleteByFilter(['@ID' => $labelIds]);
	}

	/**
	 * @return int[]
	 */
	private function findLabelIds(array $filter): array
	{
		return array_map(
			static fn (array $row): int => (int)$row['ID'],
			UserLabelTable::getList(['select' => ['ID'], 'filter' => $filter])->fetchAll(),
		);
	}

	/**
	 * Distinct labels of any user with a binding in the mailbox.
	 *
	 * @return int[]
	 */
	private function findBoundLabelIds(int $mailboxId): array
	{
		return array_map(
			static fn (array $row): int => (int)$row['LABEL_ID'],
			MessageLabelTable::getList([
				'select' => ['LABEL_ID'],
				'filter' => ['=MAILBOX_ID' => $mailboxId],
				'group' => ['LABEL_ID'],
			])->fetchAll(),
		);
	}

	private function applyBindings(int $userId, array $labelIds, array $ids, bool $assign): Result
	{
		$batchError = $this->checkBatchLimits($labelIds, $ids);
		if ($batchError !== null)
		{
			return (new Result())->addError($batchError);
		}

		$grouped = $this->groupIdsByMailbox($ids);
		if (count($grouped) > self::MAX_MAILBOXES_PER_REQUEST)
		{
			return (new Result())->addError($this->error(self::ERROR_BATCH_TOO_LARGE, 'Batch is too large'));
		}

		$groups = [];
		foreach ($grouped as $mailboxId => $group)
		{
			$groups[$mailboxId] = [
				'messageIds' => $this->resolveMessageIds($mailboxId, $group['uidIds']),
				'compositeIds' => $group['compositeIds'],
			];
		}

		return $this->writeMessageBindings($userId, $labelIds, $groups, $assign);
	}

	/**
	 * A message outside the mailbox answers access-denied, indistinguishable from no mailbox access.
	 *
	 * @param int[] $labelIds
	 */
	private function writeSingleMessageBindings(int $userId, int $mailboxId, int $messageId, array $labelIds, bool $assign): Result
	{
		$batchError = $this->checkBatchLimits($labelIds, []);
		if ($batchError !== null)
		{
			return (new Result())->addError($batchError);
		}

		if ($mailboxId <= 0 || $messageId <= 0)
		{
			return (new Result())->addError($this->error(self::ERROR_NOT_FOUND, 'Invalid mailbox or message id'));
		}

		if (!$this->messageBelongsToMailbox($mailboxId, $messageId))
		{
			return (new Result())->addError($this->error(self::ERROR_ACCESS_DENIED, 'Access denied'));
		}

		return $this->writeMessageBindings($userId, $labelIds, $this->singleMessageGroup($mailboxId, $messageId), $assign);
	}

	/**
	 * @return array<int, array{messageIds: int[], compositeIds: string[]}>
	 */
	private function singleMessageGroup(int $mailboxId, int $messageId): array
	{
		if ($mailboxId <= 0 || $messageId <= 0)
		{
			return [];
		}

		return [$mailboxId => ['messageIds' => [$messageId], 'compositeIds' => []]];
	}

	private function messageBelongsToMailbox(int $mailboxId, int $messageId): bool
	{
		return MailMessageTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $messageId,
				'=MAILBOX_ID' => $mailboxId,
			],
		]) !== null;
	}

	/**
	 * Shared write path of both entry points. Every group is checked before the first write, so a
	 * rejected batch writes nothing; the writes themselves are not transactional - a nested rollback
	 * is unsupported, and the remedy for a failure midway is an idempotent retry. processedIds echoes
	 * the caller's composite ids (empty for the message-id path).
	 *
	 * @param int[] $labelIds
	 * @param array<int, array{messageIds: int[], compositeIds: string[]}> $groups
	 */
	private function writeMessageBindings(int $userId, array $labelIds, array $groups, bool $assign): Result
	{
		$result = new Result();

		$labelIds = $this->normalizeLabelIds($labelIds);
		if ($labelIds === [] || $groups === [])
		{
			return $result->setData(['processedIds' => []]);
		}

		$labelRows = $this->findLabelRows($labelIds);

		$ownershipError = $this->validateOwnedLabels($userId, $labelIds, $labelRows);
		if ($ownershipError !== null)
		{
			$result->addError($ownershipError);

			return $result;
		}

		$labelScopes = $assign ? $this->getLabelScopes($labelIds, $labelRows) : [];

		foreach ($groups as $mailboxId => $group)
		{
			$mailboxId = (int)$mailboxId;
			if (!MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true))
			{
				$result->addError($this->error(self::ERROR_ACCESS_DENIED, 'Access denied'));

				continue;
			}

			if ($assign && !$this->labelsFitMailbox($labelScopes, $mailboxId))
			{
				$result->addError($this->error(self::ERROR_MAILBOX_MISMATCH, 'Label is pinned to another mailbox'));
			}
		}

		if (!$result->isSuccess())
		{
			return $result->setData(['processedIds' => []]);
		}

		$processedIds = [];
		$affectedMailboxIds = [];

		foreach ($groups as $mailboxId => $group)
		{
			$mailboxId = (int)$mailboxId;
			if ($group['messageIds'] === [])
			{
				continue;
			}

			$this->writeBindings($mailboxId, $group['messageIds'], $labelIds, $assign);

			$processedIds = array_merge($processedIds, $group['compositeIds']);
			$affectedMailboxIds[$mailboxId] = true;
		}

		foreach (array_keys($affectedMailboxIds) as $mailboxId)
		{
			$this->recalculateCounters($mailboxId, $labelIds);
		}

		return $result->setData(['processedIds' => $processedIds]);
	}

	/**
	 * @param int[] $messageIds
	 * @param int[] $labelIds
	 */
	private function writeBindings(int $mailboxId, array $messageIds, array $labelIds, bool $assign): void
	{
		if ($assign)
		{
			$this->insertBindings($mailboxId, $labelIds, $messageIds);

			return;
		}

		MessageLabelTable::deleteByFilter([
			'@LABEL_ID' => $labelIds,
			'=MAILBOX_ID' => $mailboxId,
			'@MESSAGE_ID' => $messageIds,
		]);
	}

	/**
	 * @param int[] $labelIds
	 */
	private function recalculateCounters(int $mailboxId, array $labelIds): void
	{
		try
		{
			$this->getCountersService()->recalculateForLabels($mailboxId, $labelIds);
		}
		catch (\Throwable $exception)
		{
			\Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	/**
	 * @param int[] $labelIds
	 * @param string[] $ids
	 */
	private function checkBatchLimits(array $labelIds, array $ids): ?Error
	{
		if (count($labelIds) > self::MAX_LABELS_PER_REQUEST || count($ids) > self::MAX_MESSAGES_PER_REQUEST)
		{
			return $this->error(self::ERROR_BATCH_TOO_LARGE, 'Batch is too large');
		}

		return null;
	}

	/**
	 * @param int[] $labelIds
	 * @return array<int, array> Label id => row.
	 */
	private function findLabelRows(array $labelIds): array
	{
		$labelRows = [];
		$rows = UserLabelTable::getList([
			'select' => ['ID', 'USER_ID', 'MAILBOX_ID'],
			'filter' => ['@ID' => $labelIds],
		]);
		while ($row = $rows->fetch())
		{
			$labelRows[(int)$row['ID']] = $row;
		}

		return $labelRows;
	}

	/**
	 * @param int[] $labelIds
	 * @param array<int, array> $labelRows
	 */
	private function validateOwnedLabels(int $userId, array $labelIds, array $labelRows): ?Error
	{
		foreach ($labelIds as $labelId)
		{
			$error = $this->checkOwnedLabel($labelRows[(int)$labelId] ?? null, $userId);
			if ($error !== null)
			{
				return $error;
			}
		}

		return null;
	}

	/**
	 * @param int[] $labelIds
	 * @param array<int, array> $labelRows
	 * @return array<int, int> Label id => scope (MAILBOX_ID).
	 */
	private function getLabelScopes(array $labelIds, array $labelRows): array
	{
		$scopes = [];
		foreach ($labelIds as $labelId)
		{
			$labelId = (int)$labelId;
			if (isset($labelRows[$labelId]))
			{
				$scopes[$labelId] = (int)$labelRows[$labelId]['MAILBOX_ID'];
			}
		}

		return $scopes;
	}

	/**
	 * Scope 0 fits every mailbox of the owner; a null scope is a gone label and fits nothing.
	 */
	private function labelFitsMailbox(?int $labelScope, int $mailboxId): bool
	{
		return $labelScope === 0 || $labelScope === $mailboxId;
	}

	/**
	 * @param array<int, int> $labelScopes
	 */
	private function labelsFitMailbox(array $labelScopes, int $mailboxId): bool
	{
		foreach ($labelScopes as $labelScope)
		{
			if (!$this->labelFitsMailbox($labelScope, $mailboxId))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string[] $ids Composite "{uidId}-{mailboxId}" identifiers.
	 * @return array<int, array{compositeIds: string[], uidIds: string[]}>
	 */
	private function groupIdsByMailbox(array $ids): array
	{
		$grouped = [];
		foreach ($ids as $id)
		{
			if (!is_scalar($id))
			{
				continue;
			}

			$parts = explode('-', (string)$id, 2);
			if (count($parts) !== 2)
			{
				continue;
			}

			[$uidId, $mailboxId] = $parts;
			$mailboxId = (int)$mailboxId;
			if ($mailboxId <= 0 || $uidId === '')
			{
				continue;
			}

			$grouped[$mailboxId]['compositeIds'][] = (string)$id;
			$grouped[$mailboxId]['uidIds'][] = $uidId;
		}

		return $grouped;
	}

	/**
	 * @param string[] $uidIds
	 * @return int[]
	 */
	private function resolveMessageIds(int $mailboxId, array $uidIds): array
	{
		$rows = MailMessageUidTable::getList([
			'select' => ['MESSAGE_ID'],
			/*
				The scope belongs here as it does in every other command over a placement: a request
				from a page opened before the source of the mailbox changed names a placement of the
				retained generation, and without it the labels of an invisible letter would change
				while every other command over the same row is refused.
			*/
			'filter' => GenerationScope::forMailbox($mailboxId)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'@ID' => $uidIds,
				'>MESSAGE_ID' => 0,
				'!@IS_OLD' => MailMessageUidTable::HIDDEN_STATUSES,
			]),
		])->fetchAll();

		return array_values(array_unique(array_map(
			static fn (array $row): int => (int)$row['MESSAGE_ID'],
			$rows,
		)));
	}

	/**
	 * @param int[] $labelIds
	 * @param int[] $messageIds
	 */
	private function insertBindings(int $mailboxId, array $labelIds, array $messageIds): void
	{
		$existing = MessageLabelTable::getList([
			'select' => ['LABEL_ID', 'MESSAGE_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'@LABEL_ID' => $labelIds,
				'@MESSAGE_ID' => $messageIds,
			],
		])->fetchAll();

		$existingKeys = [];
		foreach ($existing as $row)
		{
			$existingKeys[(int)$row['LABEL_ID'] . ':' . (int)$row['MESSAGE_ID']] = true;
		}

		$rows = [];
		foreach ($labelIds as $labelId)
		{
			foreach ($messageIds as $messageId)
			{
				if (isset($existingKeys[$labelId . ':' . $messageId]))
				{
					continue;
				}

				$rows[] = [
					'LABEL_ID' => $labelId,
					'MAILBOX_ID' => $mailboxId,
					'MESSAGE_ID' => $messageId,
				];
			}
		}

		foreach (array_chunk($rows, self::BINDINGS_INSERT_CHUNK_SIZE) as $chunk)
		{
			try
			{
				// The second argument mutes events, not duplicates: a parallel assign of the same pair
				// still collides on the primary key, and then the chunk has to go row by row.
				MessageLabelTable::addMulti($chunk, true);
			}
			catch (DuplicateEntryException)
			{
				$this->insertBindingsRowByRow($chunk);
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function insertBindingsRowByRow(array $rows): void
	{
		foreach ($rows as $row)
		{
			try
			{
				MessageLabelTable::add($row);
			}
			catch (DuplicateEntryException)
			{
			}
		}
	}

	/**
	 * @param int[] $labelIds
	 * @return int[]
	 */
	private function normalizeLabelIds(array $labelIds): array
	{
		$labelIds = array_filter(array_map('intval', $labelIds), static fn (int $id): bool => $id > 0);

		return array_values(array_unique($labelIds));
	}

	private function getCountersService(): LabelCountersService
	{
		return $this->countersService ??= new LabelCountersService();
	}

	private function checkOwnedLabel(?array $label, int $userId): ?Error
	{
		if ($label === null)
		{
			return $this->error(self::ERROR_NOT_FOUND, 'Label not found');
		}

		if ((int)$label['USER_ID'] !== $userId)
		{
			return $this->error(self::ERROR_ACCESS_DENIED, 'Access denied');
		}

		return null;
	}

	private function findLabelRow(int $labelId): ?array
	{
		return UserLabelTable::getRow([
			'select' => ['ID', 'USER_ID', 'MAILBOX_ID', 'NAME', 'SORT'],
			'filter' => ['=ID' => $labelId],
		]);
	}

	private function getDto(int $labelId): array
	{
		return $this->toDto($this->findLabelRow($labelId) ?? []);
	}

	private function toDto(array $row): array
	{
		return [
			'id' => (int)($row['ID'] ?? 0),
			'name' => (string)($row['NAME'] ?? ''),
			'mailboxId' => (int)($row['MAILBOX_ID'] ?? 0),
			'sort' => (int)($row['SORT'] ?? 0),
			'unread' => 0,
		];
	}

	private function normalizeName(string $name): string
	{
		$name = trim($name);
		if (mb_strlen($name) > self::NAME_MAX_LENGTH)
		{
			$name = mb_substr($name, 0, self::NAME_MAX_LENGTH);
		}

		return $name;
	}

	private function isLimitReached(int $userId): bool
	{
		$limit = (int)Option::get('mail', 'user_labels_limit', self::DEFAULT_LIMIT);
		if ($limit <= 0)
		{
			return false;
		}

		return UserLabelTable::getCount(['=USER_ID' => $userId]) >= $limit;
	}

	/**
	 * Uniqueness spans {global, target mailbox}: both are visible together in that mailbox. The unique
	 * key guards a single (USER_ID, MAILBOX_ID), so the cross-scope check lives here.
	 */
	private function nameExists(int $userId, int $mailboxId, string $name, ?int $excludeId = null): bool
	{
		$filter = [
			'=USER_ID' => $userId,
			'=NAME' => $name,
		];
		if ($mailboxId > 0)
		{
			$filter['@MAILBOX_ID'] = [0, $mailboxId];
		}
		if ($excludeId !== null)
		{
			$filter['!=ID'] = $excludeId;
		}

		return UserLabelTable::getRow(['select' => ['ID'], 'filter' => $filter]) !== null;
	}

	private function error(string $code, string $message): Error
	{
		return new Error($message, $code);
	}
}
