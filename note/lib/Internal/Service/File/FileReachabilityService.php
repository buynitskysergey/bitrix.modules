<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\File;

use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;

/**
 * A file is reachable within its owning document (UNIQUE(FILE_ID) guarantees no
 * cross-document ambiguity) as long as its fileId appears in the current Document.MARKDOWN
 * or in the markdown of at least one surviving version snapshot. Anything linked
 * (b_note_document_file) but not reachable is a candidate for physical deletion — see
 * VersionCleanupAgent's post-commit sweep and FileController::unlinkBatchAction,
 * which are the only two callers allowed to act on this result.
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
	 * @param DateTime|null $linkedBefore Links younger than this moment are not candidates: a file
	 *                                    uploaded through REST and not yet written into the text is
	 *                                    unreachable by definition, and only its age tells it apart
	 *                                    from an abandoned one. null means no cutoff at all — the
	 *                                    editor's explicit unlink names the file itself and needs
	 *                                    no waiting period.
	 * @return int[] fileIds linked to the document but reachable neither by its current
	 *               content nor by any surviving version snapshot.
	 */
	public function getUnreachableFileIds(int $documentId, ?DateTime $linkedBefore = null): array
	{
		return $this->filterStillUnreachable(
			$documentId,
			$this->listLinkedFileIds($documentId, $linkedBefore),
		);
	}

	/**
	 * The files linked to the document and old enough to be candidates, with no question asked yet
	 * about the text reaching them. A caller that confirms its candidates right before deleting
	 * them - the background sweep does - computes reachability once this way instead of twice, and
	 * reachability means reading MARKDOWN and every surviving version snapshot of the document.
	 *
	 * @param DateTime|null $linkedBefore as in {@see self::getUnreachableFileIds()}
	 * @return int[]
	 */
	public function listLinkedFileIds(int $documentId, ?DateTime $linkedBefore = null): array
	{
		return $documentId <= 0 ? [] : $this->getLinkedFileIds($documentId, $linkedBefore);
	}

	/**
	 * Second look at a candidate set, for a caller that is about to delete the files physically:
	 * reachability is recomputed from the document as it is right now. Between the moment the
	 * candidates were collected and CFile::Delete() a REST edit can put an old token back into the
	 * text, and the undrained-patch guard does not catch that edit — REST creates no patches.
	 *
	 * @param int[] $fileIds
	 * @return int[] Those of $fileIds the document still does not reference.
	 */
	public function filterStillUnreachable(int $documentId, array $fileIds): array
	{
		if ($documentId <= 0 || empty($fileIds))
		{
			return [];
		}

		$reachableFileIds = $this->collectReachableFileIds($documentId);

		return array_values(array_diff($fileIds, array_keys($reachableFileIds)));
	}

	/**
	 * @return int[]
	 */
	private function getLinkedFileIds(int $documentId, ?DateTime $linkedBefore): array
	{
		$fileIds = [];
		foreach ($this->fileLinkRepository->getByDocumentIds([$documentId]) as $link)
		{
			$fileId = (int)($link['FILE_ID'] ?? 0);
			if ($fileId <= 0)
			{
				continue;
			}

			if ($linkedBefore !== null && !self::isLinkedBefore($link['CREATED_AT'] ?? null, $linkedBefore))
			{
				continue;
			}

			$fileIds[$fileId] = true;
		}

		return array_keys($fileIds);
	}

	private static function isLinkedBefore(?DateTime $createdAt, DateTime $linkedBefore): bool
	{
		// A row without a readable age is treated as fresh: never delete on a guess.
		return $createdAt !== null && $createdAt->getTimestamp() < $linkedBefore->getTimestamp();
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
