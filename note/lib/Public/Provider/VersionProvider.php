<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\DocumentVersionRepository;

/**
 * Lazy body reader for a single version snapshot (preview-on-click, see API-02).
 * There is no listVersions endpoint — the timeline feed (Block 2) already carries
 * versionId/versionAvailable per content_changed event.
 */
final class VersionProvider
{
	public function __construct(
		private readonly DocumentVersionRepository $versionRepository = new DocumentVersionRepository(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
	) {}

	/**
	 * IDOR-safe by construction: ownership and view rights are always resolved from
	 * the version row's own DOCUMENT_ID, never from a caller-supplied documentId.
	 * Callers (controller) must additionally compare the returned 'documentId' against
	 * the documentId the request was made for, and treat a mismatch as 404 — this
	 * method itself does not know what documentId the caller asked for.
	 *
	 * `previousMarkdown`/`previousTitle` carry the immediately preceding snapshot (N-1) for the
	 * version-preview diff; both are '' when this is the document's first version (nothing to
	 * diff against — the whole body reads as added). The preceding version is resolved from the
	 * same DOCUMENT_ID, so it inherits the same access check as the requested version.
	 *
	 * @return array{id: int, documentId: int, markdown: string, title: string, createdAt: string, createdBy: int, previousMarkdown: string, previousTitle: string}
	 * @throws DocumentNotFoundException version does not exist (deleted by TTL, or bad id)
	 * @throws AccessDeniedException current user lacks LEVEL_VIEW on the owning document
	 */
	public function getVersionBody(int $versionId): array
	{
		$version = $this->versionRepository->getById($versionId);
		if ($version === null)
		{
			throw new DocumentNotFoundException();
		}

		$documentId = $version->getDocumentId();
		$document = $this->documentRepository->getMetaById($documentId, ['ID', 'COLLECTION_ID']);
		if ($document === null)
		{
			throw new DocumentNotFoundException();
		}

		$collectionId = (int)$document->getCollectionId();
		if (!DocumentAccessService::currentUserHasLevel($documentId, $collectionId, DocumentAccessService::LEVEL_VIEW))
		{
			throw new AccessDeniedException();
		}

		$previous = $this->versionRepository->getPreviousByDocumentAndId($documentId, $versionId);

		return [
			'id' => (int)$version->getId(),
			'documentId' => $documentId,
			'markdown' => $version->getMarkdown(),
			'title' => $version->getTitle(),
			'createdAt' => $version->getCreatedAt()->format('c'),
			'createdBy' => $version->getCreatedBy(),
			'previousMarkdown' => $previous?->getMarkdown() ?? '',
			'previousTitle' => $previous?->getTitle() ?? '',
		];
	}
}
