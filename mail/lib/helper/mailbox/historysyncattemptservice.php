<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Main\Type\DateTime;

/**
 * Counts failed attempts per message and tells which messages ran out of them.
 *
 * Written for the history sync and used by every pass that offers one message to a server again and
 * again: the tail of a source generation transfer counts its appends here as well. Which operation a
 * count belongs to is the property the counter is kept under, named at construction - one message can
 * be out of attempts of one operation and untouched by the other, and a shared count would give a
 * letter away before the second operation ever offered it.
 *
 * The counter lives outside the uid row on purpose: the row is deleted and recreated before every
 * attempt, while its id is derived from the dir, the dir generation and the UID, so it survives that.
 *
 * The second counter it keeps is per dir: the runs that let a message off its attempt. Not every
 * failure is the message's own, so some of them cost it nothing - but the excuses have to run out too,
 * or a message that breaks the link whenever its body is read is never given up on and the dir it
 * lives in is searched again every session for good.
 *
 * Besides the counters it keeps the moment a dir may be searched again. A run refused before any message
 * is singled out - the dir does not open, the server search fails, a whole batch of headers is lost - has
 * nobody to charge, so only a delay keeps it from repeating every ten minutes for as long as the server
 * stays down.
 */
final class HistorySyncAttemptService
{
	/**
	 * How long a dir refused at its own level waits before it is searched again. A run is started every
	 * ten minutes, and repeating it that often against a server that answered nothing is what the delay
	 * exists to stop; it is a fixed one, because a refusal of the whole dir tells nothing about how long
	 * the server needs.
	 */
	public const RETRY_DELAY = '40 minutes';

	private const READ_CHUNK_SIZE = 1000;

	/**
	 * @param string $attemptProperty Which operation the per message counters of this instance belong
	 *        to. The dir level state below is the history sync one either way: what it keeps is a
	 *        server that answered nothing, and that is nobody's operation in particular.
	 */
	public function __construct(
		private readonly int $mailboxId,
		private readonly string $attemptProperty = MailEntityOptionsTable::HISTORY_SYNC_ATTEMPT_COUNT_PROPERTY_NAME,
	)
	{
	}

	public static function isQuarantined(int $attemptCount): bool
	{
		return $attemptCount >= MailboxSyncManager::MAX_CONNECTION_ATTEMPTS_BEFORE_UNAVAILABLE;
	}

	/**
	 * The excuses share the limit of the attempts on purpose: three sessions is what the module already
	 * treats as enough evidence about a mail server, and a message that spends them all on a broken link
	 * is no longer told apart from a message the server refuses to give.
	 */
	public static function areRunExcusesExhausted(int $excusedRunCount): bool
	{
		return $excusedRunCount >= MailboxSyncManager::MAX_CONNECTION_ATTEMPTS_BEFORE_UNAVAILABLE;
	}

	/**
	 * @param string[] $messageRowIds
	 * @return array<string, int> uid row id => attempts made; a row without a counter is omitted
	 */
	public function getAttemptCounts(array $messageRowIds): array
	{
		$attemptCounts = [];

		foreach (array_chunk($messageRowIds, self::READ_CHUNK_SIZE) as $chunk)
		{
			$rows = MailEntityOptionsTable::getList([
				'select' => ['ENTITY_ID', 'VALUE'],
				'filter' => $this->getMessageFilter($chunk),
			])->fetchAll();

			foreach ($rows as $row)
			{
				$attemptCounts[(string)$row['ENTITY_ID']] = (int)$row['VALUE'];
			}
		}

		return $attemptCounts;
	}

	/**
	 * @param int|null $knownCount - attempts already stored for this row, 0 when it has no counter yet;
	 *        null asks for a read, which a caller that has read the counters of the whole list beforehand
	 *        does not need. A stale value costs at most one uncounted attempt, see the race below.
	 * @return int attempts made after this one is counted in
	 */
	public function registerFailedAttempt(string $messageRowId, ?int $knownCount = null): int
	{
		return $this->incrementCounter(
			MailEntityOptionsTable::MESSAGE_TYPE_NAME,
			$messageRowId,
			$this->attemptProperty,
			$knownCount ?? $this->readIntValue($this->getMessageFilter([$messageRowId])) ?? 0,
		);
	}

	/**
	 * @param string[] $messageRowIds
	 */
	public function clearAttempts(array $messageRowIds): void
	{
		foreach (array_chunk($messageRowIds, self::READ_CHUNK_SIZE) as $chunk)
		{
			MailEntityOptionsTable::deleteList($this->getMessageFilter($chunk));
		}
	}

	/**
	 * Everything a run reads about its dir before it starts, in one pass: the three values share the key
	 * prefix (MAILBOX_ID, ENTITY_TYPE, ENTITY_ID) and differ only in the property name. The coverage marker
	 * is kept by the caller, so its name is given here; this only reads it, and it is still compared with
	 * the UIDVALIDITY where it was compared before - after the dir answers.
	 *
	 * @return array{retryAfter: int|null, excusedRunCount: int, coverageMarker: string|null}
	 */
	public function readDirRunState(int $dirId, string $coverageMarkerProperty): array
	{
		$rows = MailEntityOptionsTable::getList([
			'select' => ['PROPERTY_NAME', 'VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailboxId,
				'=ENTITY_TYPE' => MailEntityOptionsTable::DIR_TYPE_NAME,
				'=ENTITY_ID' => $dirId,
				'=PROPERTY_NAME' => [
					MailEntityOptionsTable::HISTORY_SYNC_RETRY_AFTER_PROPERTY_NAME,
					MailEntityOptionsTable::HISTORY_SYNC_EXCUSED_RUNS_PROPERTY_NAME,
					$coverageMarkerProperty,
				],
			],
		])->fetchAll();

		$valueByProperty = array_column($rows, 'VALUE', 'PROPERTY_NAME');
		$retryAfter = $valueByProperty[MailEntityOptionsTable::HISTORY_SYNC_RETRY_AFTER_PROPERTY_NAME] ?? null;
		$excusedRuns = $valueByProperty[MailEntityOptionsTable::HISTORY_SYNC_EXCUSED_RUNS_PROPERTY_NAME] ?? 0;

		return [
			'retryAfter' => $retryAfter === null ? null : (int)$retryAfter,
			'excusedRunCount' => (int)$excusedRuns,
			'coverageMarker' => $valueByProperty[$coverageMarkerProperty] ?? null,
		];
	}

	/**
	 * @return int runs of this dir that let a message off its attempt, 0 when it had none
	 */
	public function getExcusedRunCount(int $dirId): int
	{
		return $this->readIntValue($this->getDirFilter($dirId)) ?? 0;
	}

	/**
	 * @param int|null $knownCount - excused runs already stored for the dir; null asks for a read, which
	 *        a caller that has read the count at the start of its run does not need.
	 */
	public function registerExcusedRun(int $dirId, ?int $knownCount = null): void
	{
		$storedCount = $knownCount ?? $this->getExcusedRunCount($dirId);

		if (self::areRunExcusesExhausted($storedCount))
		{
			// the count is a threshold and nobody reads it as a total, so it stops growing at the limit
			return;
		}

		$this->incrementCounter(
			MailEntityOptionsTable::DIR_TYPE_NAME,
			(string)$dirId,
			MailEntityOptionsTable::HISTORY_SYNC_EXCUSED_RUNS_PROPERTY_NAME,
			$storedCount,
		);
	}

	public function clearExcusedRuns(int $dirId): void
	{
		MailEntityOptionsTable::deleteList($this->getDirFilter($dirId));
	}

	/**
	 * @return int|null the moment the dir may be searched again, null when nothing postponed it
	 */
	public function getRetryAfter(int $dirId): ?int
	{
		return $this->readIntValue($this->getRetryAfterFilter($dirId));
	}

	public static function isRetryDue(?int $retryAfter): bool
	{
		return $retryAfter === null || $retryAfter <= (new DateTime())->getTimestamp();
	}

	/**
	 * The mark is approximate both ways, as the counters are: a parallel run of the same mailbox may have
	 * taken it off after it was read, and then the update below changes no row and the dir is not postponed
	 * at all. Either race costs one extra run, so nothing here is made atomic.
	 *
	 * @param bool $isStored - whether the dir already carries the mark, known to a caller that read it at
	 *        the start of its run: the write is an update then and an insert otherwise.
	 */
	public function postponeRetry(int $dirId, bool $isStored): void
	{
		$retryAfter = (new DateTime())->add(self::RETRY_DELAY);
		$value = (string)$retryAfter->getTimestamp();
		$writtenAt = new DateTime();

		if (!$isStored)
		{
			// the insert of a parallel run of the same mailbox is dropped: it postpones the dir to the same moment
			MailEntityOptionsTable::insertIgnore(
				$this->mailboxId,
				(string)$dirId,
				MailEntityOptionsTable::DIR_TYPE_NAME,
				MailEntityOptionsTable::HISTORY_SYNC_RETRY_AFTER_PROPERTY_NAME,
				$value,
				$writtenAt,
			);

			return;
		}

		MailEntityOptionsTable::update(
			[
				'MAILBOX_ID' => $this->mailboxId,
				'ENTITY_TYPE' => MailEntityOptionsTable::DIR_TYPE_NAME,
				'ENTITY_ID' => (string)$dirId,
				'PROPERTY_NAME' => MailEntityOptionsTable::HISTORY_SYNC_RETRY_AFTER_PROPERTY_NAME,
			],
			[
				'VALUE' => $value,
				'DATE_INSERT' => $writtenAt,
			],
		);
	}

	public function clearPostponedRetry(int $dirId): void
	{
		MailEntityOptionsTable::deleteList($this->getRetryAfterFilter($dirId));
	}

	/**
	 * @param int $storedCount - the value already stored, 0 when there is no record yet
	 * @return int the value after this increment
	 */
	private function incrementCounter(
		string $entityType,
		string $entityId,
		string $propertyName,
		int $storedCount,
	): int
	{
		$count = $storedCount + 1;
		$writtenAt = new DateTime();

		if ($storedCount === 0)
		{
			/*
				Two simultaneous runs of the same mailbox may reach this point together: the insert of
				the loser is dropped instead of failing. Both counters are approximate by design, and
				the race costs at most one uncounted increment.
			*/
			MailEntityOptionsTable::insertIgnore(
				$this->mailboxId,
				$entityId,
				$entityType,
				$propertyName,
				(string)$count,
				$writtenAt,
			);

			return $count;
		}

		MailEntityOptionsTable::update(
			[
				'MAILBOX_ID' => $this->mailboxId,
				'ENTITY_TYPE' => $entityType,
				'ENTITY_ID' => $entityId,
				'PROPERTY_NAME' => $propertyName,
			],
			[
				'VALUE' => (string)$count,
				'DATE_INSERT' => $writtenAt,
			],
		);

		return $count;
	}

	private function readIntValue(array $filter): ?int
	{
		$row = MailEntityOptionsTable::getRow([
			'select' => ['VALUE'],
			'filter' => $filter,
		]);

		return isset($row['VALUE']) ? (int)$row['VALUE'] : null;
	}

	/**
	 * @param string[] $messageRowIds
	 */
	private function getMessageFilter(array $messageRowIds): array
	{
		return [
			'=MAILBOX_ID' => $this->mailboxId,
			'=ENTITY_TYPE' => MailEntityOptionsTable::MESSAGE_TYPE_NAME,
			'=ENTITY_ID' => $messageRowIds,
			'=PROPERTY_NAME' => $this->attemptProperty,
		];
	}

	private function getRetryAfterFilter(int $dirId): array
	{
		return [
			'=MAILBOX_ID' => $this->mailboxId,
			'=ENTITY_TYPE' => MailEntityOptionsTable::DIR_TYPE_NAME,
			'=ENTITY_ID' => $dirId,
			'=PROPERTY_NAME' => MailEntityOptionsTable::HISTORY_SYNC_RETRY_AFTER_PROPERTY_NAME,
		];
	}

	private function getDirFilter(int $dirId): array
	{
		return [
			'=MAILBOX_ID' => $this->mailboxId,
			'=ENTITY_TYPE' => MailEntityOptionsTable::DIR_TYPE_NAME,
			'=ENTITY_ID' => $dirId,
			'=PROPERTY_NAME' => MailEntityOptionsTable::HISTORY_SYNC_EXCUSED_RUNS_PROPERTY_NAME,
		];
	}
}
