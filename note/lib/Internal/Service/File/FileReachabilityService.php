<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\File;

use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;

/**
 * [P4.T2] A file is reachable within its owning document (UNIQUE(FILE_ID) guarantees no
 * cross-document ambiguity) as long as its fileId appears in the current Document.MARKDOWN
 * or in the markdown of at least one surviving version snapshot. Anything linked
 * (b_note_document_file) but not reachable is a candidate for physical deletion — see
 * VersionCleanupAgent's post-commit sweep ([P4.T3]) and FileController::unlinkBatchAction
 * ([P4.T4]), which are the only two callers allowed to act on this result.
 *
 * Reachability here is computed against IMMUTABLE version snapshots, never against live
 * in-progress content — mixing the two was the source of the race that got the previous
 * auto-cleanup feature pulled (see project_note_file_cleanup_history memory note).
 */
class FileReachabilityService
{
	public function __construct(
		private readonly DocumentFileLinkRepository $fileLinkRepository = new DocumentFileLinkRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly DocumentVersionRepository $documentVersionRepository = new DocumentVersionRepository(),
	)
	{
	}

	/**
	 * @return int[] fileIds linked to the document but reachable neither by its current
	 *               content nor by any surviving version snapshot.
	 */
	public function getUnreachableFileIds(int $documentId): array
	{
		if ($documentId <= 0)
		{
			return [];
		}

		$linkedFileIds = $this->getLinkedFileIds($documentId);
		if (empty($linkedFileIds))
		{
			return [];
		}

		$reachableFileIds = $this->collectReachableFileIds($documentId);

		return array_values(array_diff($linkedFileIds, array_keys($reachableFileIds)));
	}

	/**
	 * @return int[]
	 */
	private function getLinkedFileIds(int $documentId): array
	{
		$fileIds = [];
		foreach ($this->fileLinkRepository->getByDocumentIds([$documentId]) as $link)
		{
			$fileId = (int)($link['FILE_ID'] ?? 0);
			if ($fileId > 0)
			{
				$fileIds[$fileId] = true;
			}
		}

		return array_keys($fileIds);
	}

	/**
	 * @return array<int, true> Set of fileIds reachable by current content or any live version.
	 */
	private function collectReachableFileIds(int $documentId): array
	{
		$reachable = [];

		$currentMarkdown = $this->documentRepository->getRawMarkdown($documentId);
		if ($currentMarkdown !== null && $currentMarkdown !== '')
		{
			foreach (AssetTokenParser::extractFileIds($currentMarkdown) as $fileId)
			{
				$reachable[$fileId] = true;
			}
		}

		foreach ($this->documentVersionRepository->getMarkdownsByDocument($documentId) as $markdown)
		{
			foreach (AssetTokenParser::extractFileIds($markdown) as $fileId)
			{
				$reachable[$fileId] = true;
			}
		}

		return $reachable;
	}
}
