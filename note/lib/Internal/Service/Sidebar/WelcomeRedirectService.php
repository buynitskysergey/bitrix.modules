<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Sidebar;

use Bitrix\Main\Config\Option;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;

/**
 * Decides whether a /note/ entry should bypass the workspace and open the
 * welcome document directly. Returns the target document id for the very first
 * visit of a user who can still reach the seeded welcome content; null in any
 * other case. Caller is responsible for persisting the per-user has_visited
 * flag — this service is read-only.
 */
class WelcomeRedirectService
{
	private DocumentRepository $documentRepository;
	private RecycleBinRepository $recycleBinRepository;

	public function __construct(
		?DocumentRepository $documentRepository = null,
		?RecycleBinRepository $recycleBinRepository = null,
	)
	{
		$this->documentRepository = $documentRepository ?? new DocumentRepository();
		$this->recycleBinRepository = $recycleBinRepository ?? new RecycleBinRepository();
	}

	public function resolveFor(int $userId): ?int
	{
		if ($userId <= 0)
		{
			return null;
		}

		try
		{
			$hasVisited = \CUserOptions::GetOption('note', 'has_visited', null, $userId);
			if ($hasVisited === 'Y')
			{
				return null;
			}

			$documentId = (int)Option::get('note', 'welcome_document_id', '');
			if ($documentId <= 0)
			{
				return null;
			}

			$document = $this->documentRepository->getById($documentId);
			if ($document === null || $document->getIsArchived())
			{
				return null;
			}

			if ($this->recycleBinRepository->getByDocumentId($documentId) !== null)
			{
				return null;
			}

			$collectionId = (int)$document->getCollectionId();
			if ($collectionId <= 0)
			{
				return null;
			}

			// The default is right here: the welcome document is a live landing point, and an
			// archived one is already refused above.
			$snapshot = DocumentAccessService::getCurrentUserSnapshot($documentId, $collectionId, false);
			if (!$snapshot['canViewCollection'])
			{
				return null;
			}

			return $documentId;
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
