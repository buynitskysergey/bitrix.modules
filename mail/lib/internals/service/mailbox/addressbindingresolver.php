<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals\Service\Mailbox;

use Bitrix\Mail\Internals\Model\MailboxAddressAliasTable;
use Bitrix\Mail\MailboxTable;

final class AddressBindingResolver
{
	private const QUERY_CHUNK_SIZE = 100;

	private readonly EmailNormalizer $emailNormalizer;

	public function __construct(?EmailNormalizer $emailNormalizer = null)
	{
		$this->emailNormalizer = $emailNormalizer ?? new EmailNormalizer();
	}

	public function resolveForUser(int $userId, string $siteId, string $email): ?ResolvedMailboxBinding
	{
		return $this->findBindings($email, $userId, $siteId)[0] ?? null;
	}

	/**
	 * @return array<int, array>
	 */
	public function findActiveMailboxesByIds(array $mailboxIds, ?string $serverType = null): array
	{
		return $this->loadMailboxesByIds($mailboxIds, true, $serverType);
	}

	/**
	 * @return array<int, array>
	 */
	public function findMailboxesByIds(array $mailboxIds): array
	{
		return $this->loadMailboxesByIds($mailboxIds, false);
	}

	private function loadMailboxesByIds(array $mailboxIds, bool $activeOnly, ?string $serverType = null): array
	{
		$mailboxIds = array_values(array_filter(
			array_unique(array_map('intval', $mailboxIds)),
			static fn(int $mailboxId): bool => $mailboxId > 0,
		));
		$result = [];
		foreach (array_chunk($mailboxIds, self::QUERY_CHUNK_SIZE) as $mailboxIdChunk)
		{
			$filter = ['@ID' => $mailboxIdChunk];
			if ($activeOnly)
			{
				$filter['=ACTIVE'] = 'Y';
			}
			if ($serverType !== null)
			{
				$filter['=SERVER_TYPE'] = $serverType;
			}

			$mailboxes = MailboxTable::getList([
				'select' => ['ID', 'EMAIL', 'NAME', 'LOGIN', 'USER_ID', 'SERVER_TYPE'],
				'filter' => $filter,
			]);
			while ($mailbox = $mailboxes->fetch())
			{
				$result[(int)$mailbox['ID']] = $mailbox;
			}
		}

		return $result;
	}

	/**
	 * @return ResolvedMailboxBinding[]
	 */
	public function findBindings(string $email, ?int $userId = null, ?string $siteId = null): array
	{
		$normalizedEmail = $this->emailNormalizer->normalize($email);
		if ($normalizedEmail === null)
		{
			return [];
		}

		return $this->findBindingsForEmails([$normalizedEmail], $userId, $siteId)[$normalizedEmail] ?? [];
	}

	/**
	 * @param string[] $emails
	 * @return array<string, ResolvedMailboxBinding[]>
	 */
	public function findBindingsForEmails(array $emails, ?int $userId = null, ?string $siteId = null): array
	{
		if ($userId !== null && $userId <= 0)
		{
			return [];
		}

		$result = $this->normalizeEmails($emails);
		if ($result === [])
		{
			return [];
		}

		$mailboxes = $userId === null ? null : $this->getUserMailboxesInScope($userId, $siteId);
		$exactMatches = $mailboxes === null
			? $this->findGlobalExactMatches(array_keys($result), $siteId)
			: $this->findExactMatches($mailboxes, array_keys($result))
		;

		foreach ($exactMatches as $email => $matchedMailboxes)
		{
			$result[$email] = $this->buildResults($matchedMailboxes, false);
		}

		$unresolvedEmails = array_keys(array_filter($result, static fn(array $bindings): bool => $bindings === []));
		if ($unresolvedEmails === [])
		{
			return $result;
		}

		$aliasMailboxIdsByEmail = $this->findAliasMailboxIdsByEmails($unresolvedEmails);
		if ($aliasMailboxIdsByEmail === [])
		{
			return $result;
		}

		$aliasMailboxIds = array_values(array_unique(array_merge(...array_values($aliasMailboxIdsByEmail))));
		$aliasMailboxes = $mailboxes === null ? $this->findGlobalMailboxesByIds($aliasMailboxIds, $siteId) : [];
		$mailboxesById = [];
		foreach ($aliasMailboxes as $mailbox)
		{
			$mailboxesById[(int)$mailbox['ID']] = $mailbox;
		}

		foreach ($aliasMailboxIdsByEmail as $email => $mailboxIds)
		{
			$matchedMailboxes = $mailboxes === null ? [] : $this->filterMailboxesByIds($mailboxes, $mailboxIds);
			if ($mailboxes === null)
			{
				foreach ($mailboxIds as $mailboxId)
				{
					if (isset($mailboxesById[$mailboxId]))
					{
						$matchedMailboxes[] = $mailboxesById[$mailboxId];
					}
				}
			}

			$result[$email] = $this->buildResults($matchedMailboxes, true);
		}

		return $result;
	}

	private function normalizeEmails(array $emails): array
	{
		$result = [];
		foreach ($emails as $email)
		{
			$normalizedEmail = $this->emailNormalizer->normalize((string)$email);
			if ($normalizedEmail !== null)
			{
				$result[$normalizedEmail] = [];
			}
		}

		return $result;
	}

	private function getUserMailboxesInScope(int $userId, ?string $siteId): array
	{
		$mailboxes = MailboxTable::getUserMailboxes($userId);
		if ($siteId === null)
		{
			return array_values($mailboxes);
		}

		return array_values(array_filter(
			$mailboxes,
			static fn(array $mailbox): bool => $mailbox['LID'] === $siteId,
		));
	}

	private function findGlobalExactMatches(array $normalizedEmails, ?string $siteId): array
	{
		$mailboxes = [];
		foreach (array_chunk($normalizedEmails, self::QUERY_CHUNK_SIZE) as $emailChunk)
		{
			$filter = ['@EMAIL_NORMALIZED' => $emailChunk];
			if ($siteId !== null)
			{
				$filter['=LID'] = $siteId;
			}

			$mailboxes = array_merge($mailboxes, MailboxTable::getList([
				'select' => ['ID', 'EMAIL', 'NAME', 'LOGIN', 'EMAIL_NORMALIZED'],
				'filter' => $filter,
			])->fetchAll());
		}

		return $this->groupMailboxesByEmail($mailboxes, $normalizedEmails);
	}

	private function findGlobalMailboxesByIds(array $mailboxIds, ?string $siteId): array
	{
		$mailboxesById = [];
		foreach (array_chunk($mailboxIds, self::QUERY_CHUNK_SIZE) as $mailboxIdChunk)
		{
			$filter = ['@ID' => $mailboxIdChunk];
			if ($siteId !== null)
			{
				$filter['=LID'] = $siteId;
			}

			$mailboxes = MailboxTable::getList([
				'select' => ['ID', 'EMAIL'],
				'filter' => $filter,
			])->fetchAll();
			foreach ($mailboxes as $mailbox)
			{
				$mailboxesById[(int)$mailbox['ID']] = $mailbox;
			}
		}

		$result = [];
		foreach ($mailboxIds as $mailboxId)
		{
			if (isset($mailboxesById[$mailboxId]))
			{
				$result[] = $mailboxesById[$mailboxId];
			}
		}

		return $result;
	}

	private function findExactMatches(array $mailboxes, array $normalizedEmails): array
	{
		return $this->groupMailboxesByEmail($mailboxes, $normalizedEmails);
	}

	private function groupMailboxesByEmail(array $mailboxes, array $normalizedEmails): array
	{
		$emailMap = array_fill_keys($normalizedEmails, true);
		$result = [];
		foreach ($mailboxes as $mailbox)
		{
			$normalizedEmail = $this->emailNormalizer->normalizeMailbox($mailbox);
			if ($normalizedEmail !== null && isset($emailMap[$normalizedEmail]))
			{
				$result[$normalizedEmail][] = $mailbox;
			}
		}

		return $result;
	}

	private function findAliasMailboxIdsByEmails(array $emails): array
	{
		$result = [];
		foreach (array_chunk($emails, self::QUERY_CHUNK_SIZE) as $emailChunk)
		{
			$rows = MailboxAddressAliasTable::getList([
				'select' => ['MAILBOX_ID', 'EMAIL'],
				'filter' => ['@EMAIL' => $emailChunk],
			])->fetchAll();
			foreach ($rows as $row)
			{
				$result[(string)$row['EMAIL']][] = (int)$row['MAILBOX_ID'];
			}
		}

		return $result;
	}

	private function filterMailboxesByIds(array $mailboxes, array $mailboxIds): array
	{
		$mailboxIdMap = array_fill_keys($mailboxIds, true);

		return array_values(array_filter(
			$mailboxes,
			static fn(array $mailbox): bool => isset($mailboxIdMap[(int)$mailbox['ID']]),
		));
	}

	/**
	 * @return ResolvedMailboxBinding[]
	 */
	private function buildResults(array $mailboxes, bool $isAlias): array
	{
		return array_map(
			static fn(array $mailbox): ResolvedMailboxBinding => new ResolvedMailboxBinding(
				mailboxId: (int)$mailbox['ID'],
				currentEmail: (string)$mailbox['EMAIL'],
				isAlias: $isAlias,
			),
			$mailboxes,
		);
	}
}
