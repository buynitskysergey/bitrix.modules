<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Entity\History\DocumentVersion;
use Bitrix\Note\Internal\Model\DocumentVersionTable;

class DocumentVersionRepository
{
	/**
	 * @throws PersistenceException
	 */
	public function save(DocumentVersion $version): DocumentVersion
	{
		$result = DocumentVersionTable::add([
			'DOCUMENT_ID' => $version->getDocumentId(),
			'MARKDOWN' => $version->getMarkdown(),
			'TITLE' => $version->getTitle(),
			'CREATED_BY' => $version->getCreatedBy(),
			'CREATED_AT' => $version->getCreatedAt(),
		]);

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to save document version: ' . implode(', ', $result->getErrorMessages()),
			);
		}

		return $version->withId((int)$result->getId());
	}

	/**
	 * Full-row read by primary key, including the MARKDOWN/TITLE body. Used only by
	 * the lazy preview (VersionProvider) and restore (RestoreDocumentVersionCommand) —
	 * never for listing, so no caching/select-narrowing concerns apply here.
	 */
	public function getById(int $id): ?DocumentVersion
	{
		$row = DocumentVersionTable::query()
			->setSelect(['ID', 'DOCUMENT_ID', 'MARKDOWN', 'TITLE', 'CREATED_BY', 'CREATED_AT'])
			->where('ID', $id)
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		$createdAt = $row['CREATED_AT'] ?? null;
		if (!($createdAt instanceof DateTime))
		{
			$createdAt = $createdAt ? DateTime::createFromUserTime((string)$createdAt) : new DateTime();
		}

		return new DocumentVersion(
			(int)$row['ID'],
			(int)$row['DOCUMENT_ID'],
			(string)$row['MARKDOWN'],
			(string)$row['TITLE'],
			(int)$row['CREATED_BY'],
			$createdAt,
		);
	}

	/**
	 * [P3.T1] Identity probe of the most recent version snapshot (highest ID = latest by CREATED_AT), or
	 * null when the document has no versions yet. Compaction compares the freshly materialized text
	 * against that snapshot to suppress a no-op version (the pre-compact MARKDOWN can no longer serve as
	 * the baseline, since materialization keeps it equal to the incoming text), and it asks on every
	 * compaction. Bodies are MEDIUMTEXT, so the answer is reduced to a byte length and an MD5 computed by
	 * the database: the body itself is read back only when both already match, see
	 * CompactDocumentCommand::differsFromLatestVersion.
	 *
	 * Both numbers describe the STORED value, so a caller comparing them against a PHP string must first
	 * put that string through the same save modifier the field uses (Emoji::encode).
	 *
	 * @return array{id: int, length: int, hash: string}|null
	 */
	public function getLatestFingerprint(int $documentId): ?array
	{
		$row = DocumentVersionTable::getList([
			'select' => ['ID', 'MARKDOWN_LENGTH', 'MARKDOWN_HASH'],
			'filter' => ['=DOCUMENT_ID' => $documentId],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
			'runtime' => [
				new ExpressionField('MARKDOWN_LENGTH', 'OCTET_LENGTH(%s)', ['MARKDOWN']),
				new ExpressionField('MARKDOWN_HASH', 'MD5(%s)', ['MARKDOWN']),
			],
		])->fetch();

		if ($row === false)
		{
			return null;
		}

		return [
			'id' => (int)$row['ID'],
			'length' => (int)$row['MARKDOWN_LENGTH'],
			'hash' => (string)$row['MARKDOWN_HASH'],
		];
	}

	/**
	 * The snapshot immediately preceding $versionId within the same document — the N-1 the
	 * version-preview diff compares against. Ordered by ID (monotonic with CREATED_AT), so
	 * "previous" is the highest ID below $versionId for that document. Returns null when
	 * $versionId is the document's first snapshot (nothing to diff against — the whole version
	 * reads as added).
	 */
	public function getPreviousByDocumentAndId(int $documentId, int $versionId): ?DocumentVersion
	{
		$row = DocumentVersionTable::query()
			->setSelect(['ID', 'DOCUMENT_ID', 'MARKDOWN', 'TITLE', 'CREATED_BY', 'CREATED_AT'])
			->where('DOCUMENT_ID', $documentId)
			->where('ID', '<', $versionId)
			->addOrder('ID', 'DESC')
			->setLimit(1)
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		$createdAt = $row['CREATED_AT'] ?? null;
		if (!($createdAt instanceof DateTime))
		{
			$createdAt = $createdAt ? DateTime::createFromUserTime((string)$createdAt) : new DateTime();
		}

		return new DocumentVersion(
			(int)$row['ID'],
			(int)$row['DOCUMENT_ID'],
			(string)$row['MARKDOWN'],
			(string)$row['TITLE'],
			(int)$row['CREATED_BY'],
			$createdAt,
		);
	}

	/**
	 * Oldest-first batch of {id, documentId} pairs older than $cutoff, for TTL cleanup
	 * (VersionCleanupAgent). No cursor param is needed: the agent deletes each batch before
	 * requesting the next one, so the same query naturally surfaces the next-oldest rows
	 * (mirrors RecycleBinRepository::listExpiredAt). DOCUMENT_ID rides along so the agent can
	 * scope the post-commit file-reachability sweep ([P4.T3]) to exactly the documents touched
	 * by this batch, without a second query.
	 *
	 * @return array<int, array{id: int, documentId: int}>
	 */
	public function listExpiredAt(DateTime $cutoff, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = DocumentVersionTable::query()
			->setSelect(['ID', 'DOCUMENT_ID'])
			->where('CREATED_AT', '<', $cutoff)
			->addOrder('CREATED_AT', 'ASC')
			->addOrder('ID', 'ASC')
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(
			static fn(array $row): array => [
				'id' => (int)$row['ID'],
				'documentId' => (int)$row['DOCUMENT_ID'],
			],
			$rows,
		);
	}

	/**
	 * Streams MARKDOWN bodies of every live (non-expired) version of one document, chunked by an
	 * ID cursor so the caller ({@see \Bitrix\Note\Internal\Service\File\FileReachabilityService})
	 * never holds more than one chunk of version bodies in memory at a time — it accumulates only
	 * the fileIds extracted from each body, not the bodies themselves.
	 *
	 * @return iterable<string>
	 */
	public function getMarkdownsByDocument(int $documentId, int $chunkSize = 100): iterable
	{
		if ($documentId <= 0 || $chunkSize <= 0)
		{
			return;
		}

		$afterId = 0;
		while (true)
		{
			$rows = DocumentVersionTable::query()
				->setSelect(['ID', 'MARKDOWN'])
				->where('DOCUMENT_ID', $documentId)
				->where('ID', '>', $afterId)
				->addOrder('ID', 'ASC')
				->setLimit($chunkSize)
				->fetchAll()
			;

			if (empty($rows))
			{
				break;
			}

			foreach ($rows as $row)
			{
				yield (string)($row['MARKDOWN'] ?? '');
			}

			$afterId = (int)$rows[count($rows) - 1]['ID'];

			if (count($rows) < $chunkSize)
			{
				break;
			}
		}
	}

	/**
	 * [P2.T3] Batch existence check for feed enrichment: a content_changed event's
	 * VERSION_ID may have already been TTL-cleaned (see VersionCleanupAgent) — the
	 * feed marks such events with versionAvailable=false (non-interactive node)
	 * instead of erroring out on click.
	 *
	 * @param int[] $ids
	 * @return int[] subset of $ids that still have a live version row
	 */
	public function existingIds(array $ids): array
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return [];
		}

		$rows = DocumentVersionTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $normalized)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * @param int[] $ids
	 */
	public function deleteByIds(array $ids): void
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return;
		}

		DocumentVersionTable::deleteByFilter(['=ID' => $normalized]);
	}

	/**
	 * @param int[] $documentIds
	 */
	public function deleteByDocumentIds(array $documentIds): void
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return;
		}

		DocumentVersionTable::deleteByFilter(['=DOCUMENT_ID' => $normalized]);
	}
}
