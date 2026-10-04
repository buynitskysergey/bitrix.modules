<?php

namespace Bitrix\Mail\Helper\Message;

use Bitrix\Mail\Helper\Dto\Message\StartDateCacheDto;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Main\ORM;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;

final class MessageInternalDateHandler
{
	/**
	 * How many folder rows a single invalidation pass reads and updates. Bounded on purpose: an argument-less
	 * call clears every folder of the portal, and a portal with thousands of mailboxes must not read the whole
	 * set into memory or update it in one statement. Exposed so a test can force more than one pass.
	 */
	public const CLEAR_START_DATE_PORTION = 500;

	/**
	 * The countable messages of a folder - the complement of MailMessageUidTable::EXCLUDED_COUNTER_STATUSES
	 * over the closed IS_OLD domain. Listed rather than excluded on purpose: only an equality set keeps
	 * INTERNALDATE an ordered tail of IX_MAIL_MSG_UID_DIR_START_DATE. A new IS_OLD state has to be
	 * decided upon here, otherwise this set and the invalidation one drift apart silently.
	 */
	private const COUNTABLE_STATUSES = [
		MailMessageUidTable::RECENT,
		MailMessageUidTable::DOWNLOADED,
	];

	/**
	 * Whether a message row belongs to the countable set of its folder - the same fields
	 * getMinCountableInternalDateForDir() aggregates over. Single source of truth for both invalidation
	 * points ({@see MailMessageUidTable::onAfterUpdate}, {@see MessageEventManager::checkForNeedClearCache}).
	 * Fail-closed: a row that cannot prove it is countable is not counted. The move payload does carry
	 * DELETE_TIME and MESSAGE_ID (imapcommands/repository.php adds them on purpose), so both fields are
	 * load-bearing for cache invalidation; the guard only shields other callers whose array may lack a field.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function isCountableMessageRow(array $row): bool
	{
		return isset($row['IS_OLD'])
			&& in_array($row['IS_OLD'], self::COUNTABLE_STATUSES, true)
			&& isset($row['DELETE_TIME']) && (int)$row['DELETE_TIME'] === 0
			&& isset($row['MESSAGE_ID']) && (int)$row['MESSAGE_ID'] > 0
			&& isset($row['INTERNALDATE']) && $row['INTERNALDATE'] !== null
		;
	}

	public static function getStartInternalDateForDir(
		$mailboxId,
		?string $dirPath = null,
		?string $dirMd5 = null,
	): ?DateTime
	{
		$filter = [
			'=MAILBOX_ID' => $mailboxId,
		];

		if (!is_null($dirPath))
		{
			$filter['=PATH'] = Emoji::encode($dirPath);
		}
		else if (!is_null($dirMd5))
		{
			$filter['=DIR_MD5'] = $dirMd5;
		}

		$startInternalData = self::fetchDirCacheRow((int)$mailboxId, $filter);

		if (empty($startInternalData['ID'] ?? null))
		{
			return null;
		}

		if ($startInternalData['IS_DATE_CACHED'])
		{
			return $startInternalData['INTERNAL_START_DATE'] ?? null;
		}

		// The row just read is the folder itself, so its own DIR_MD5 is what keys its letters
		return self::recalculateStartInternalDateForDir(
			(int)$mailboxId,
			(string)($startInternalData['DIR_MD5'] ?? ''),
		);
	}

	/**
	 * Reads the cache without touching it. One query tells the three states apart: no DTO means either the
	 * folder is gone or its cache is invalid, a DTO with a null value means a valid cache of an empty
	 * folder, and a DTO with a value means a valid cache holding that date.
	 */
	public static function getCachedStartInternalDateForDir(int $mailboxId, string $dirMd5): ?StartDateCacheDto
	{
		$startInternalData = self::fetchDirCacheRow($mailboxId, [
			'=MAILBOX_ID' => $mailboxId,
			'=DIR_MD5' => $dirMd5,
		]);

		if ($startInternalData === null || !$startInternalData['IS_DATE_CACHED'])
		{
			return null;
		}

		return new StartDateCacheDto($startInternalData['INTERNAL_START_DATE'] ?? null);
	}

	/**
	 * Counts the start date of the folder anew and stores it as valid. The folder is looked up here rather
	 * than passed in, so that the caller cannot make the write land on a folder the date was not counted
	 * for; a folder that is already gone is simply not written to.
	 */
	public static function recalculateStartInternalDateForDir(int $mailboxId, string $dirMd5): ?DateTime
	{
		$startInternalData = self::fetchDirCacheRow($mailboxId, [
			'=MAILBOX_ID' => $mailboxId,
			'=DIR_MD5' => $dirMd5,
		]);

		if (empty($startInternalData['ID'] ?? null))
		{
			return null;
		}

		$startInternalDate = self::getMinCountableInternalDateForDir($mailboxId, $dirMd5);

		self::setStartInternalDateForDir([
			'ID' => $startInternalData['ID'],
			'INTERNAL_START_DATE' => $startInternalDate,
		]);

		return $startInternalDate;
	}

	/**
	 * The folder row holding the cache. One path of one mailbox has a folder row per source generation
	 * after a change of the source, and they answer to the same path and the same hash - so without the
	 * scope the row of the archive is as likely to be read and written as the one serving the user.
	 */
	private static function fetchDirCacheRow(int $mailboxId, array $filter): ?array
	{
		$row = MailboxDirectoryTable::query()
			->setSelect(['ID', 'DIR_MD5', 'INTERNAL_START_DATE', 'IS_DATE_CACHED'])
			->setFilter(GenerationScope::forMailbox($mailboxId)->apply($filter))
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	public static function setStartInternalDateForDir(
		array $startInternalDate
	): void
	{
		MailboxDirectoryTable::update($startInternalDate['ID'], [
			'INTERNAL_START_DATE' => $startInternalDate['INTERNAL_START_DATE'] ?? null,
			'IS_DATE_CACHED' => true,
		]);
	}

	private static function getFirstSyncMessageFromMailMessageTable(
		int $mailboxId,
		?string $dirPath,
		?string $dirMd5 = null,
	): ?array
	{

		if (is_null($dirMd5) && !is_null($dirPath))
		{
			$dirMd5 = md5(Emoji::encode($dirPath));
		}

		$filter = [
			'=MESSAGE_UID.DELETE_TIME' => 0,
			'!@MESSAGE_UID.IS_OLD' => MailMessageUidTable::EXCLUDED_COUNTER_STATUSES,
		];

		$firstSyncMessage = MailMessageTable::getList(
			[
				'runtime' => [
					new ORM\Fields\Relations\Reference(
						'MESSAGE_UID', MailMessageUidTable::class, [
						'=this.MAILBOX_ID' => 'ref.MAILBOX_ID',
						'=this.ID' => 'ref.MESSAGE_ID',
					], [
							'join_type' => 'INNER',
						]
					),
				],
				'select' => [
					'INTERNAL_START_DATE' => 'MESSAGE_UID.INTERNALDATE',
				],
				'filter' => array_merge(
					[
						'=MAILBOX_ID' => $mailboxId,
						'=MESSAGE_UID.DIR_MD5' => $dirMd5,
						'!=MESSAGE_UID.INTERNALDATE' => NULL,
					],
					$filter
				),
				'order' => [
					'FIELD_DATE' => 'ASC',
				],
				'limit' => 1,
			]
		)->fetchAll();

		return $firstSyncMessage[0] ?? null;
	}

	/**
	 * The minimum INTERNALDATE over the countable messages of the folder. Every query fixes the generation
	 * and status to keep the ordered date inside one exact prefix of the placement index. Combining their
	 * minima in PHP preserves the result without letting an optimizer read retained generations first.
	 */
	private static function getMinCountableInternalDateForDir(int $mailboxId, string $dirMd5): ?DateTime
	{
		$generationIds = GenerationScope::forMailbox($mailboxId)->getGenerationIds();
		$generationIds = $generationIds ?? [null];
		$minInternalDate = null;

		foreach ($generationIds as $generationId)
		{
			foreach (self::COUNTABLE_STATUSES as $status)
			{
				$candidate = self::getMinExistingInternalDateForScope(
					$mailboxId,
					$dirMd5,
					$generationId,
					$status,
				);
				if ($candidate instanceof DateTime
					&& ($minInternalDate === null || $candidate->getTimestamp() < $minInternalDate->getTimestamp()))
				{
					$minInternalDate = $candidate;
				}
			}
		}

		return $minInternalDate;
	}

	private static function getMinExistingInternalDateForScope(
		int $mailboxId,
		string $dirMd5,
		?int $generationId,
		string $status,
	): ?DateTime
	{
		$query = MailMessageUidTable::query()
			->registerRuntimeField('EXISTING_MESSAGE', new ORM\Fields\Relations\Reference(
				'EXISTING_MESSAGE',
				MailMessageTable::class,
				ORM\Query\Join::on('this.MESSAGE_ID', 'ref.ID')
					->whereColumn('this.MAILBOX_ID', 'ref.MAILBOX_ID'),
				['join_type' => ORM\Query\Join::TYPE_INNER],
			))
			->setSelect(['INTERNALDATE'])
			->where('MAILBOX_ID', $mailboxId)
			->where('DIR_MD5', $dirMd5)
			->where('DELETE_TIME', 0)
			->where('IS_OLD', $status)
			->whereNotNull('INTERNALDATE')
			->where('MESSAGE_ID', '>', 0)
			->whereNotNull('EXISTING_MESSAGE.ID')
			->setOrder(['INTERNALDATE' => 'ASC', 'MESSAGE_ID' => 'ASC'])
			->setLimit(1)
		;

		if ($generationId !== null)
		{
			$query->where('GENERATION_ID', $generationId);
		}

		$candidate = $query->fetch();

		return $candidate['INTERNALDATE'] ?? null;
	}

	public static function clearStartInternalDate(?int $mailboxId = null, ?string $dirMd5 = null): ORM\Data\UpdateResult
	{
		$filter = ['=IS_DATE_CACHED' => true];
		if ($mailboxId !== null)
		{
			$filter['=MAILBOX_ID'] = $mailboxId;

			if ($dirMd5 !== null)
			{
				$filter['=DIR_MD5'] = $dirMd5;
			}
		}

		$result = new ORM\Data\UpdateResult();

		$lastId = 0;
		$upperId = (int)(MailboxDirectoryTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])->fetch()['ID'] ?? 0);
		if ($upperId === 0)
		{
			return $result;
		}

		// Walk a fixed ID range so every portion starts after the previous one. Rows recached concurrently
		// behind the cursor are left for the next invalidation run instead of making this one unbounded.
		while (true)
		{
			$ids = array_column(
				MailboxDirectoryTable::getList([
					'select' => ['ID'],
					'filter' => array_merge($filter, [
						'>ID' => $lastId,
						'<=ID' => $upperId,
					]),
					'order' => ['ID' => 'ASC'],
					'limit' => self::CLEAR_START_DATE_PORTION,
				])->fetchAll(),
				'ID',
			);

			if (empty($ids))
			{
				break;
			}

			$portionResult = MailboxDirectoryTable::updateMulti(
				$ids,
				[
					'IS_DATE_CACHED' => false,
					'INTERNAL_START_DATE' => null,
				],
				true,
			);

			if (!$portionResult->isSuccess())
			{
				$result->addErrors($portionResult->getErrors());

				break;
			}

			$lastId = (int)end($ids);
		}

		return $result;
	}
}
