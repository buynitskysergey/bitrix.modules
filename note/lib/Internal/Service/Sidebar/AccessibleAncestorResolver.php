<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Sidebar;

use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Entity\Document\BreadcrumbAncestor;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Repository\DocumentRepository;

/**
 * Breadcrumb chain for a document reached through a document-level grant (no collection VIEW).
 *
 * Only the contiguous run of ancestors the user can actually open survives: the first inaccessible
 * ancestor truncates everything above it, so a hidden document is never revealed by its title. This
 * is the same privacy rule AccessibleTreeService applies when it turns the nearest accessible
 * descendant into a branch root.
 */
final class AccessibleAncestorResolver
{
	private const MAX_DEPTH = 200;

	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
	) {}

	/**
	 * @return BreadcrumbAncestor[] ordered from the topmost visible ancestor down to the direct
	 *         parent; empty when nothing above the document is visible
	 */
	public function resolve(int $documentId, int $collectionId, int $userId): array
	{
		if ($documentId <= 0 || $collectionId <= 0 || $userId <= 0)
		{
			return [];
		}

		$path = $this->documentRepository->getDocumentPathToRoot($documentId, self::MAX_DEPTH);
		$ancestors = [];
		foreach ($path as $ancestor)
		{
			if (!$ancestor instanceof Document)
			{
				continue;
			}

			$id = (int)$ancestor->getId();
			if ($id <= 0)
			{
				continue;
			}

			$ancestors[] = new BreadcrumbAncestor($id, (string)$ancestor->getTitle());
		}
		if ($ancestors === [])
		{
			return [];
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = DocumentAccessService::batchGetEffectiveLevels(
			array_map(
				static fn(BreadcrumbAncestor $ancestor): array => [
					'id' => $ancestor->id,
					'collectionId' => $collectionId,
				],
				$ancestors,
			),
			$accessCodes,
			$userId,
		);

		$visible = [];
		foreach ($ancestors as $ancestor)
		{
			if (($levels[$ancestor->id] ?? DocumentAccessService::LEVEL_NONE) < DocumentAccessService::LEVEL_VIEW)
			{
				$visible = [];

				continue;
			}

			$visible[] = $ancestor;
		}

		return $visible;
	}
}
