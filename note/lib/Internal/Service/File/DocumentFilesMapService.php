<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\File;

use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentUpdateRepository;
use Bitrix\Note\Internal\Service\DocumentFileService;
use Bitrix\Note\Internal\Service\NoteFileUrlService;

/**
 * The module's own files of one document with their payload, handed out with the document itself
 * so that the editor does not ask for them in a second request. A file missing from the map is
 * resolved the old way, which is also where a file borrowed from another document is adopted.
 *
 * The ordinary circuit gets every linked file: its reader may open the versions, and a file removed
 * from the text stays linked while some version still references it. The public circuit gets only
 * the files the current text references - a visitor sees no versions. When MARKDOWN may lag behind
 * the journal the visitor merges, the text cannot vouch for any file and the map stays empty.
 */
final class DocumentFilesMapService
{
	// The ceiling of the resolve batch: a document with more files gets the rest by resolve.
	public const MAX_ITEMS = 200;

	public function __construct(
		private readonly DocumentFileLinkRepository $linkRepository = new DocumentFileLinkRepository(),
		private readonly DocumentFileService $documentFileService = new DocumentFileService(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentUpdateRepository $updateRepository = new DocumentUpdateRepository(),
	)
	{
	}

	/**
	 * @return array<int, array<string, mixed>> payload by file id
	 */
	public function build(int $documentId, bool $public = false): array
	{
		if ($documentId <= 0)
		{
			return [];
		}

		$fileIds = array_map(
			static fn(array $link): int => (int)$link['FILE_ID'],
			$this->linkRepository->getByDocumentIds([$documentId]),
		);
		if ($fileIds === [])
		{
			return [];
		}

		if ($public)
		{
			$fileIds = array_intersect($fileIds, $this->getReferencedFileIds($documentId));
		}
		sort($fileIds);
		$fileIds = array_slice(array_values(array_unique($fileIds)), 0, self::MAX_ITEMS);

		$validatedFiles = $this->documentFileService->getValidatedNoteFilesMap($fileIds);

		$map = [];
		foreach ($fileIds as $fileId)
		{
			if (!isset($validatedFiles[$fileId]))
			{
				continue;
			}

			$payload = $public
				? $this->documentFileService->buildFilePayload(
					$fileId,
					showUrl: NoteFileUrlService::createPublicShowUrl($fileId),
					downloadUrl: NoteFileUrlService::createPublicDownloadUrl($fileId),
					fileData: $validatedFiles[$fileId],
					previewUrl: NoteFileUrlService::createPublicShowUrl($fileId, preview: true),
				)
				: $this->documentFileService->buildFilePayload($fileId, fileData: $validatedFiles[$fileId])
			;

			if ($payload !== null)
			{
				$map[$fileId] = $payload;
			}
		}

		return $map;
	}

	/**
	 * @return int[]
	 */
	private function getReferencedFileIds(int $documentId): array
	{
		if ($this->isMarkdownBehindJournal($documentId))
		{
			return [];
		}

		// Read after the cursor: MARKDOWN only moves forward, so it covers at least what was checked.
		$markdown = $this->documentRepository->getRawMarkdown($documentId);

		return $markdown === null || $markdown === '' ? [] : AssetTokenParser::extractFileIds($markdown);
	}

	/**
	 * A collaborative document is served as YJS_STATE plus the journal, while MARKDOWN covers the
	 * journal only up to MATERIALIZED_UPTO_ID. The cursor is read before the journal head, so a patch
	 * landing in between reads as a lag, never as a fresh text.
	 */
	private function isMarkdownBehindJournal(int $documentId): bool
	{
		$document = $this->documentRepository->getMetaById(
			$documentId,
			['ID', 'CONTENT_FORMAT', 'MATERIALIZED_UPTO_ID'],
			useCache: false,
		);
		if ($document === null || $document->getContentFormat() !== DocumentTable::CONTENT_FORMAT_YJS)
		{
			return false;
		}

		$materializedUptoId = $document->getMaterializedUptoId() ?? 0;
		$journalLastId = $this->updateRepository->getLastId($documentId);

		return $journalLastId !== null && $journalLastId > $materializedUptoId;
	}
}
