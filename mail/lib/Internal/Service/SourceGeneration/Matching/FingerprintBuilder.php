<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration\Matching;

use Bitrix\Mail\Internals\MessageFingerprintTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * The write side of the matching (ALG-01): hashes of the canonical views and the
 * batch build of the fingerprints the candidate lookup needs.
 *
 * Only SHA-256 hashes reach the table - content and addresses are never stored
 * there in the open. The canonicalization version is part of every row, so a
 * future version never collides with the current one.
 */
final class FingerprintBuilder
{
	private const BATCH_SIZE = 200;

	/** Fingerprint rows one insert statement carries */
	private const INSERT_BATCH_SIZE = 500;

	public function __construct(
		private readonly CandidateProvider $candidates = new CandidateProvider(),
	)
	{
	}

	public function hashContent(CanonicalMessageData $data): string
	{
		return hash('sha256', $data->getContentPayload());
	}

	/**
	 * Every content hash the letter can be found by {@see CanonicalMessageData::getContentPayloadVariants()}.
	 * The first one is the hash of the letter itself - the one a representation of it is stored under.
	 *
	 * @return string[]
	 */
	public function hashContentVariants(CanonicalMessageData $data): array
	{
		return array_map(
			static fn (string $payload): string => hash('sha256', $payload),
			$data->getContentPayloadVariants(),
		);
	}

	public function hashMessageId(CanonicalMessageData $data): string
	{
		return $data->hasMessageId() ? hash('sha256', $data->getMessageIdPayload()) : '';
	}

	/**
	 * The transferable identifier a managed migrator keeps for one of our messages.
	 */
	public function hashMigratorReference(string $reference): string
	{
		$reference = trim($reference);

		return $reference === ''
			? ''
			: hash('sha256', 'mail-canonical/' . CanonicalMessageNormalizer::VERSION . '/migrator' . "\n" . $reference)
		;
	}

	/**
	 * Adds the fingerprints of one representation of a logical message. Repeating
	 * the call adds nothing: a representation equal to an already stored one has
	 * the same hashes.
	 *
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function storeRepresentation(
		int $mailboxId,
		int $messageId,
		int $generationId,
		CanonicalMessageData $data,
		?string $migratorReference = null,
	): void
	{
		$this->addMissing($mailboxId, $generationId, [
			[
				'messageId' => $messageId,
				'hashes' => [
					MessageFingerprintTable::KIND_CONTENT => $this->hashContent($data),
					MessageFingerprintTable::KIND_MESSAGE_ID => $this->hashMessageId($data),
					MessageFingerprintTable::KIND_MIGRATOR_ID => $this->hashMigratorReference((string)$migratorReference),
				],
				'isComplete' => $data->hasBody,
			],
		]);
	}

	/**
	 * Binds transferable identifiers of a managed migrator to local messages. The
	 * correspondence comes from the manifest or the API of that migrator; a MIME
	 * header is only an optional channel of the same value.
	 *
	 * @param array<string, int> $references Transferable identifier => local message.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function storeMigratorReferences(int $mailboxId, int $generationId, array $references): void
	{
		$representations = [];

		foreach ($references as $reference => $messageId)
		{
			$representations[] = [
				'messageId' => (int)$messageId,
				'hashes' => [
					MessageFingerprintTable::KIND_MIGRATOR_ID => $this->hashMigratorReference((string)$reference),
				],
				'isComplete' => false,
			];
		}

		$this->addMissing($mailboxId, $generationId, $representations);
	}

	/**
	 * Builds the missing fingerprints of one page of local messages.
	 *
	 * @return int The last message of the page, 0 when the mailbox is fully covered.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function buildBatch(int $mailboxId, int $afterMessageId, int $limit = self::BATCH_SIZE): int
	{
		$messageIds = $this->candidates->listMessageIds($mailboxId, $afterMessageId, $limit);
		if ($messageIds === [])
		{
			return 0;
		}

		$fingerprinted = $this->candidates->findFingerprintedMessages($mailboxId, $messageIds);
		$missing = array_values(array_filter(
			$messageIds,
			static fn(int $messageId) => !isset($fingerprinted[$messageId]),
		));

		$representations = [];

		/*
			The canonical view of a message carries its whole body, so the letters of a page are
			canonicalized in bounded groups and only their hashes are kept: the page of the walk
			is counted in letters, and the body of a letter has no size limit at all.
		*/
		foreach (array_chunk($missing, CandidateProvider::CANONICAL_PAGE_SIZE) as $group)
		{
			foreach ($this->candidates->loadCanonical($mailboxId, $group) as $messageId => $data)
			{
				$representations[] = [
					'messageId' => $messageId,
					'hashes' => [
						MessageFingerprintTable::KIND_CONTENT => $this->hashContent($data),
						MessageFingerprintTable::KIND_MESSAGE_ID => $this->hashMessageId($data),
					],
					'isComplete' => $data->hasBody,
				];
			}
		}

		$this->addMissing($mailboxId, 0, $representations);

		return (int)end($messageIds);
	}

	/**
	 * Adds the fingerprints a page of representations does not carry yet. What the page
	 * already holds is read for the whole page at once: a walk over the history of a
	 * mailbox must not cost a round trip per message.
	 *
	 * @param array{messageId: int, hashes: array<string, string>, isComplete: bool}[] $representations
	 *        Empty hashes and unknown messages are skipped.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function addMissing(int $mailboxId, int $generationId, array $representations): void
	{
		$page = [];

		foreach ($representations as $representation)
		{
			$hashes = array_filter($representation['hashes'], static fn(string $hash) => $hash !== '');

			if ($representation['messageId'] > 0 && $hashes !== [])
			{
				$page[] = [
					'messageId' => $representation['messageId'],
					'hashes' => $hashes,
					'isComplete' => $representation['isComplete'],
				];
			}
		}

		if ($mailboxId <= 0 || $page === [])
		{
			return;
		}

		$stored = $this->loadStoredHashes($mailboxId, array_column($page, 'messageId'));
		$now = new DateTime();
		$rows = [];

		foreach ($page as $representation)
		{
			$messageId = $representation['messageId'];

			foreach ($representation['hashes'] as $kind => $hash)
			{
				if (isset($stored[$messageId][$kind][$hash]))
				{
					continue;
				}

				$rows[] = [
					'MAILBOX_ID' => $mailboxId,
					'MESSAGE_ID' => $messageId,
					'GENERATION_ID' => max($generationId, 0),
					'KIND' => $kind,
					'ALGORITHM_VERSION' => CanonicalMessageNormalizer::VERSION,
					'HASH' => $hash,
					'IS_COMPLETE' => $representation['isComplete'] ? 'Y' : 'N',
					'DATE_CREATE' => $now,
				];

				$stored[$messageId][$kind][$hash] = true;
			}
		}

		$this->insert($rows);
	}

	/**
	 * The missing fingerprints of the page as a whole. The build walks the entire history of
	 * a mailbox, so this is the most repeated write of a migration: a row of its own per
	 * fingerprint would cost a round trip per hash of every letter.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function insert(array $rows): void
	{
		foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $chunk)
		{
			/*
				The events of the table are skipped on purpose: the ORM cannot report the
				identifiers of a multi-row insert, so it falls back to a query per row for an
				entity that has any. Nothing subscribes to this internal table of the matching.
			*/
			MessageFingerprintTable::addMulti($chunk, true);
		}
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, array<string, array<string, true>>> Message => kind => hash => true.
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function loadStoredHashes(int $mailboxId, array $messageIds): array
	{
		$rows = MessageFingerprintTable::getList([
			'select' => ['MESSAGE_ID', 'KIND', 'HASH'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'@MESSAGE_ID' => array_values(array_unique($messageIds)),
				'=ALGORITHM_VERSION' => CanonicalMessageNormalizer::VERSION,
			],
		])->fetchAll();

		$stored = [];
		foreach ($rows as $row)
		{
			$stored[(int)$row['MESSAGE_ID']][(string)$row['KIND']][(string)$row['HASH']] = true;
		}

		return $stored;
	}
}
