<?php

namespace Bitrix\Sign\Access\Service;

use Bitrix\Main;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\AccessController\AccessControllerFactory;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Sign\SignersList\AccessService as SignersListAccessService;

/**
 * Authorizes entity-selector sources before they are expanded into signers.
 *
 * Signers lists and sign documents are expansion sources: the request supplies an id and the
 * domain reads the members behind it. Without this guard any user could read the composition of
 * a foreign list or document. Called from controller actions only, right after payload
 * validation: expansion engines stay free of user context, so system flows are untouched.
 */
class SelectorSourceAccessService
{
	private const SOURCE_CHECK_CHUNK_SIZE = 300;

	/** @var array<int, AccessController|null> */
	private array $accessControllers = [];

	public function __construct(
		private readonly SignersListAccessService $signersListAccessService,
		private readonly DocumentRepository $documentRepository,
		private readonly AccessControllerFactory $accessControllerFactory,
		private readonly Feature $feature,
	)
	{
	}

	/**
	 * Sources coming from the member/signer payload of a controller action.
	 */
	public function checkSelectorEntities(Item\Member\SelectorEntityCollection $entities): Main\Result
	{
		$sources = [];
		foreach ($entities as $entity)
		{
			$sources[] = Item\Hr\EntitySelector\Entity::createFromStrings(
				entityId: $entity->entityId,
				entityType: $entity->entityType,
			);
		}

		return $this->checkSources($sources);
	}

	/**
	 * Sources already normalized into typed entities.
	 */
	public function checkEntityCollection(Item\Hr\EntitySelector\EntityCollection $entities): Main\Result
	{
		$sources = [];
		/** @var Item\Hr\EntitySelector\Entity $entity */
		foreach ($entities as $entity)
		{
			$sources[] = $entity;
		}

		return $this->checkSources($sources);
	}

	/**
	 * @param list<Item\Hr\EntitySelector\Entity> $sources
	 */
	private function checkSources(array $sources): Main\Result
	{
		$result = new Main\Result();
		$uniqueSources = $this->deduplicateSources($sources);

		// The number of sources comes from the request, so they are checked in bounded portions:
		// each portion costs one document read and one list read, and only the portion being
		// checked is held in memory. A denial leaves the remaining portions unread.
		foreach (array_chunk($uniqueSources, self::SOURCE_CHECK_CHUNK_SIZE) as $portion)
		{
			if (!$this->arePortionSourcesAccessible($portion))
			{
				return $result->addError($this->createAccessDeniedError());
			}
		}

		return $result;
	}

	/**
	 * @param list<Item\Hr\EntitySelector\Entity> $sources
	 */
	private function arePortionSourcesAccessible(array $sources): bool
	{
		$documents = $this->loadDocuments($sources);
		$accessibleListIds = $this->loadAccessibleListIds($sources);

		foreach ($sources as $source)
		{
			if (!$this->isSourceAccessible($source, $documents, $accessibleListIds))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The request may repeat the same source any number of times, so each distinct source is
	 * checked once. Order of the first occurrence is kept: the denial still comes from the
	 * earliest inaccessible source.
	 *
	 * @param list<Item\Hr\EntitySelector\Entity> $sources
	 *
	 * @return array<string, Item\Hr\EntitySelector\Entity>
	 */
	private function deduplicateSources(array $sources): array
	{
		$uniqueSources = [];
		foreach ($sources as $source)
		{
			$uniqueSources[$source->entityType->value . ':' . $source->entityId] ??= $source;
		}

		return $uniqueSources;
	}

	/**
	 * @param array<int, Item\Document> $documents
	 * @param array<int, true> $accessibleListIds
	 */
	private function isSourceAccessible(
		Item\Hr\EntitySelector\Entity $source,
		array $documents,
		array $accessibleListIds,
	): bool
	{
		if ($source->entityType->isSignersList())
		{
			return isset($accessibleListIds[$source->entityId]);
		}

		if ($source->entityType->isDocument())
		{
			return $this->isDocumentAccessible($source->entityId, $documents);
		}

		// Users, departments and CRM entities are not sign-owned access objects.
		return true;
	}

	/**
	 * @param array<int, Item\Document> $documents
	 */
	private function isDocumentAccessible(int $entityId, array $documents): bool
	{
		// Feature check goes first: a disabled tab must not even reveal whether the document exists.
		if (!$this->feature->isDocumentsInSignersSelectorEnabled())
		{
			return false;
		}

		if ($entityId < 1)
		{
			return false;
		}

		$document = $documents[$entityId] ?? null;
		if ($document === null)
		{
			return false;
		}

		$accessController = $this->getAccessController();
		if ($accessController === null)
		{
			return false;
		}

		return $accessController->checkByItem(ActionDictionary::ACTION_B2E_DOCUMENT_READ, $document);
	}

	/**
	 * All signers list rules, including the rejected list bypass and the owner scope, belong to
	 * the list access service and are intentionally not reproduced here. Only the verdict is kept:
	 * the list items themselves are of no use to this guard.
	 *
	 * @param list<Item\Hr\EntitySelector\Entity> $sources
	 *
	 * @return array<int, true>
	 */
	private function loadAccessibleListIds(array $sources): array
	{
		$ids = [];
		foreach ($sources as $source)
		{
			if ($source->entityType->isSignersList())
			{
				$ids[] = $source->entityId;
			}
		}

		if ($ids === [])
		{
			return [];
		}

		$accessibleLists = $this->signersListAccessService->getAccessibleLists(
			$ids,
			ActionDictionary::ACTION_B2E_SIGNERS_LIST_READ,
		);

		return array_fill_keys(array_keys($accessibleLists), true);
	}

	/**
	 * Reads the document sources of the portion in one query — the batch pattern the item-aware
	 * prefilter uses in Engine\ActionFilter\AccessCheck::createAccessibleItems(). The portion size
	 * bounds the id list, so a payload cannot turn into a single oversized IN (...).
	 * The feature check repeats the one in isDocumentAccessible() on purpose: with the tab
	 * disabled the repository must not be touched at all.
	 *
	 * @param list<Item\Hr\EntitySelector\Entity> $sources
	 *
	 * @return array<int, Item\Document>
	 */
	private function loadDocuments(array $sources): array
	{
		if (!$this->feature->isDocumentsInSignersSelectorEnabled())
		{
			return [];
		}

		$ids = [];
		foreach ($sources as $source)
		{
			if ($source->entityType->isDocument() && $source->entityId > 0)
			{
				$ids[] = $source->entityId;
			}
		}

		if ($ids === [])
		{
			return [];
		}

		$documents = [];
		foreach ($this->documentRepository->listByIds($ids) as $document)
		{
			if ($document->id !== null)
			{
				$documents[$document->id] = $document;
			}
		}

		return $documents;
	}

	private function getAccessController(): ?AccessController
	{
		// Keyed by user id: the current user can change inside one process, and the service is a
		// per-request singleton. A null controller is memoized too, so a userId < 1 does not send
		// the factory a request per source.
		$userId = (int)Main\Engine\CurrentUser::get()->getId();
		if (!array_key_exists($userId, $this->accessControllers))
		{
			$this->accessControllers[$userId] = $this->accessControllerFactory->createByUserId($userId);
		}

		return $this->accessControllers[$userId];
	}

	private function createAccessDeniedError(): Main\Error
	{
		// Same code the controllers answer with through Engine\Controller::addAccessDeniedError():
		// both read the one declaration in main, so result and response cannot drift apart.
		return new Main\Error(
			'Access denied.',
			Main\Engine\ActionFilter\Authentication::ERROR_INVALID_AUTHENTICATION,
		);
	}
}
