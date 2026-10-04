<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration\Matching;

use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\Internals\MessageFingerprintTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;

/**
 * The read side of the matching (ALG-01): indexed candidate lookups and the
 * canonical view of the local messages they point to.
 *
 * A candidate is always a logical MESSAGE_ID, never the uid row of a particular
 * generation, so several fingerprints of one message collapse into one candidate
 * and no G1 -> G2 -> G3 chain is ever built.
 *
 * Every lookup is answered by one query per batch of hashes: the algorithm must
 * not degrade into a walk over the history of the mailbox.
 */
final class CandidateProvider
{
	/**
	 * Messages whose stored representation is read at once. The row of a message carries both
	 * of its bodies, and both are long text: a page counted in messages alone is unbounded in
	 * bytes, and a hundred letters with a body of a megabyte is a hundred megabytes in one
	 * answer of the server.
	 */
	public const CANONICAL_PAGE_SIZE = 50;

	private const UNSYNC_BODY_PROPERTY = 'UNSYNC_BODY';

	/**
	 * The columns whose map field decodes the emoji markers of the stored value, read here
	 * as they really are: the incoming side is parsed out of a MIME, and that parse writes
	 * such a marker itself, so a decoded stored value would meet an encoded incoming one.
	 *
	 * A field of an expression inherits no read modifier of the column it substitutes. Its
	 * name has to differ from that column, or the substitution names itself.
	 *
	 * @var array<string, string> The column => the field the page reads it under.
	 */
	private const UNDECODED_FIELDS = [
		'SUBJECT' => 'SUBJECT_AS_STORED',
		'BODY_HTML' => 'BODY_HTML_AS_STORED',
	];

	/** How many message ids a page of the history walk spans per message it asks for */
	private const PAGE_WINDOW_FACTOR = 4;

	/** @var array<int, int> Mailbox => the id window its last page needed */
	private array $pageWindow = [];

	public function __construct(
		private readonly CanonicalMessageNormalizer $normalizer = new CanonicalMessageNormalizer(),
	)
	{
	}

	/**
	 * @return int[] Logical messages the hash points to, each one once.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function findByHash(int $mailboxId, string $kind, string $hash): array
	{
		if ($hash === '')
		{
			return [];
		}

		return $this->findByHashes($mailboxId, $kind, [$hash])[$hash] ?? [];
	}

	/**
	 * @param string[] $hashes
	 * @return array<string, int[]> Hash => logical messages, each one once.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function findByHashes(int $mailboxId, string $kind, array $hashes): array
	{
		$hashes = array_values(array_unique(array_filter($hashes, static fn(string $hash) => $hash !== '')));
		if ($mailboxId <= 0 || $hashes === [])
		{
			return [];
		}

		$rows = MessageFingerprintTable::getList([
			'select' => ['MESSAGE_ID', 'HASH'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=KIND' => $kind,
				'=ALGORITHM_VERSION' => CanonicalMessageNormalizer::VERSION,
				'@HASH' => $hashes,
			],
		])->fetchAll();

		$found = [];
		foreach ($rows as $row)
		{
			$messageId = (int)$row['MESSAGE_ID'];
			if ($messageId > 0)
			{
				$found[(string)$row['HASH']][$messageId] = $messageId;
			}
		}

		return array_map(array_values(...), $found);
	}

	/**
	 * The canonical view of local messages, rebuilt from what they actually kept, page by
	 * page: the bodies held at once are bounded by {@see CANONICAL_PAGE_SIZE} and not by the
	 * number of messages asked for. A caller that decides per message takes them from here,
	 * so the page it does not need any more is released before the next one is read.
	 *
	 * @param int[] $messageIds
	 * @return \Generator<int, CanonicalMessageData>
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function streamCanonical(int $mailboxId, array $messageIds): \Generator
	{
		$messageIds = $this->normalizeIds($messageIds);
		if ($mailboxId <= 0 || $messageIds === [])
		{
			return;
		}

		foreach (array_chunk($messageIds, self::CANONICAL_PAGE_SIZE) as $page)
		{
			yield from $this->loadCanonicalPage($mailboxId, $page);
		}
	}

	/**
	 * The same canonical views collected into one answer, for a caller that asks about a
	 * group already bounded by a page of its own. What such a group holds is the bound of
	 * this answer too, so a group that is not bounded belongs to {@see streamCanonical()}.
	 *
	 * @param int[] $messageIds
	 * @return array<int, CanonicalMessageData>
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function loadCanonical(int $mailboxId, array $messageIds): array
	{
		$canonical = [];

		foreach ($this->streamCanonical($mailboxId, $messageIds) as $messageId => $data)
		{
			$canonical[$messageId] = $data;
		}

		return $canonical;
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, CanonicalMessageData>
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function loadCanonicalPage(int $mailboxId, array $messageIds): array
	{
		$attachmentNames = $this->loadAttachmentNames($messageIds);
		$withoutBody = $this->loadMessagesWithoutBody($mailboxId, $messageIds);

		$runtime = [];
		foreach (self::UNDECODED_FIELDS as $column => $field)
		{
			$runtime[] = new ExpressionField($field, '%s', [$column]);
		}

		$rows = MailMessageTable::getList([
			'runtime' => $runtime,
			'select' => [
				'ID',
				'MSG_ID',
				'FIELD_FROM',
				'FIELD_TO',
				'FIELD_CC',
				'HEADER',
				'BODY',
				'OPTIONS',
				...array_values(self::UNDECODED_FIELDS),
			],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'@ID' => $messageIds,
			],
		])->fetchAll();

		$canonical = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];

			$canonical[$id] = $this->normalizer->fromStoredMessage(
				$this->withUndecodedFields($row),
				$attachmentNames[$id] ?? [],
				!isset($withoutBody[$id]),
			);
		}

		return $canonical;
	}

	/**
	 * The row a stored message is canonicalized from: every column under its own name,
	 * the undecoded ones included.
	 */
	private function withUndecodedFields(array $row): array
	{
		foreach (self::UNDECODED_FIELDS as $column => $field)
		{
			$row[$column] = (string)($row[$field] ?? '');
		}

		return $row;
	}

	/**
	 * Logical messages of the mailbox after the cursor, oldest first.
	 *
	 * The page is taken from a bounded window of message ids and never from the whole
	 * remainder of the mailbox. Only a two sided range is answered by (MAILBOX_ID, ID)
	 * alone; an open ended one is answered by an equality over the mailbox plus a sort of
	 * everything it still holds, which is what makes a full walk over the history
	 * quadratic. Every window starts at a message that really exists, so a mailbox whose
	 * letters are rare among the messages of the portal costs no empty query, and the width
	 * that a page needed is remembered for the next one.
	 *
	 * @return int[]
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function listMessageIds(int $mailboxId, int $afterMessageId, int $limit): array
	{
		$limit = max($limit, 1);
		$cursor = max($afterMessageId, 0);
		$window = max($this->pageWindow[$mailboxId] ?? 0, $limit * self::PAGE_WINDOW_FACTOR);

		$ids = [];

		while (count($ids) < $limit)
		{
			$from = $this->findNextMessageId($mailboxId, $cursor);
			if ($from <= 0)
			{
				break;
			}

			$remaining = $limit - count($ids);
			$upperBound = $from + $window - 1;
			$page = $this->listMessageIdsOfWindow($mailboxId, $from, $upperBound, $remaining);

			foreach ($page as $id)
			{
				$ids[] = $id;
			}

			// A window cut short by the budget keeps its tail for the next page
			$cursor = count($page) === $remaining ? (int)end($page) : $upperBound;
			$window = $this->adjustWindow($window, $limit, $from, $cursor, count($page) < $remaining);
		}

		$this->pageWindow[$mailboxId] = $window;

		return $ids;
	}

	/**
	 * Messages of the batch that already carry a content fingerprint of the current version.
	 *
	 * @param int[] $messageIds
	 * @return array<int, true>
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function findFingerprintedMessages(int $mailboxId, array $messageIds): array
	{
		$messageIds = $this->normalizeIds($messageIds);
		if ($messageIds === [])
		{
			return [];
		}

		$rows = MessageFingerprintTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=KIND' => MessageFingerprintTable::KIND_CONTENT,
				'=ALGORITHM_VERSION' => CanonicalMessageNormalizer::VERSION,
				'@MESSAGE_ID' => $messageIds,
			],
		])->fetchAll();

		$found = [];
		foreach ($rows as $row)
		{
			$found[(int)$row['MESSAGE_ID']] = true;
		}

		return $found;
	}

	/**
	 * The first message of the mailbox after the cursor, 0 when it has none. The index over
	 * (MAILBOX_ID, ID) answers it without reading a row.
	 *
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function findNextMessageId(int $mailboxId, int $afterMessageId): int
	{
		$row = MailMessageTable::getList([
			'runtime' => [new ExpressionField('NEXT_ID', 'MIN(%s)', 'ID')],
			'select' => ['NEXT_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'>ID' => $afterMessageId,
			],
		])->fetch();

		return (int)($row['NEXT_ID'] ?? 0);
	}

	/**
	 * @return int[] Messages of the window, oldest first.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function listMessageIdsOfWindow(int $mailboxId, int $from, int $to, int $limit): array
	{
		$rows = MailMessageTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'>=ID' => $from,
				'<=ID' => $to,
			],
			'order' => ['ID' => 'ASC'],
			'limit' => $limit,
		])->fetchAll();

		return array_map(static fn(array $row) => (int)$row['ID'], $rows);
	}

	/**
	 * The width of the next window, kept close to the span a page really occupies: too
	 * narrow a window costs a query per handful of letters, too wide a one stops looking
	 * like a range to the planner and brings the sort back.
	 */
	private function adjustWindow(int $window, int $limit, int $from, int $reached, bool $spent): int
	{
		if ($spent)
		{
			return $window * 2;
		}

		return max($limit * self::PAGE_WINDOW_FACTOR, min($window, ($reached - $from + 1) * 2));
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, string[]>
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function loadAttachmentNames(array $messageIds): array
	{
		$rows = MailMessageAttachmentTable::getList([
			'select' => ['MESSAGE_ID', 'FILE_NAME'],
			'filter' => ['@MESSAGE_ID' => $messageIds],
		])->fetchAll();

		$names = [];
		foreach ($rows as $row)
		{
			$names[(int)$row['MESSAGE_ID']][] = (string)$row['FILE_NAME'];
		}

		return $names;
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, true> Messages whose body has not been downloaded yet.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function loadMessagesWithoutBody(int $mailboxId, array $messageIds): array
	{
		$rows = MailEntityOptionsTable::getList([
			'select' => ['ENTITY_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ENTITY_TYPE' => MailEntityOptionsTable::MESSAGE_TYPE_NAME,
				'=PROPERTY_NAME' => self::UNSYNC_BODY_PROPERTY,
				'=VALUE' => 'Y',
				'@ENTITY_ID' => array_map(strval(...), $messageIds),
			],
		])->fetchAll();

		$found = [];
		foreach ($rows as $row)
		{
			$found[(int)$row['ENTITY_ID']] = true;
		}

		return $found;
	}

	/**
	 * @param int[] $messageIds
	 * @return int[]
	 */
	private function normalizeIds(array $messageIds): array
	{
		$ids = [];
		foreach ($messageIds as $messageId)
		{
			$messageId = (int)$messageId;
			if ($messageId > 0)
			{
				$ids[$messageId] = $messageId;
			}
		}

		return array_values($ids);
	}
}
