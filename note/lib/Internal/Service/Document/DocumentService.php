<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Infrastructure\Agent\Access\SubtreeAclReconcileScheduler;
use Bitrix\Note\Internal\Exceptions\CollectionNotFoundException;
use Bitrix\Note\Internal\Exceptions\ParentDocumentMismatchException;
use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Access\SubtreeAclReconciler;
use Bitrix\Note\Internal\Service\Document\Position\PositionCalculator;
use Bitrix\Note\Internal\Service\DocumentFileService;
use Bitrix\Note\Internal\Service\Link\DocumentLinkIndexService;
use Bitrix\Note\Internal\Service\Search\SearchIndexService;
use Bitrix\Note\Internal\Service\User\SystemUser;
use Bitrix\Note\Internal\Util\IdNormalizer;

class DocumentService
{
	public function __construct(
		private readonly DocumentRepository $repository = new DocumentRepository(),
		private readonly DocumentFileService $documentFileService = new DocumentFileService(),
		private readonly PositionCalculator $positionCalculator = new PositionCalculator(),
		private readonly SearchIndexService $searchIndexService = new SearchIndexService(),
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly SubtreeAclReconciler $subtreeAclReconciler = new SubtreeAclReconciler(),
		private readonly DocumentLinkIndexService $documentLinkIndexService = new DocumentLinkIndexService(),
	)
	{
	}

	/**
	 * Creates a new document.
	 *
	 * @throws SystemException on validation or persistence failure
	 */
	public function create(
		int $collectionId,
		?int $parentId,
		string $title,
		string $markdown,
		int $userId,
		string $contentFormat = DocumentTable::CONTENT_FORMAT_YJS,
	): Document
	{
		$this->assertCollectionExists($collectionId);
		$this->assertParentBelongsToCollection($parentId, $collectionId);

		$position = $this->positionCalculator->calculateNextPosition(
			$this->repository->getMaxPosition($collectionId, $parentId)
		);

		$document = DocumentTable::createObject()
			->setCollectionId($collectionId)
			->setParentId($parentId)
			->setTitle($title)
			->setMarkdown($markdown)
			->setContentFormat($contentFormat)
			->setPosition($position)
			->setIsArchived(false)
			->setCreatedBy($userId)
			->setUpdatedBy($userId)
			// [P1.T4] create() indexes both derived projections (search and links) inline, so the
			// content build time is recorded and the derived flag stays 'N' — nothing for the agent.
			->setContentUpdatedAt(new DateTime())
			->setDerivedStale(false)
		;

		$saveResult = $this->repository->save($document);
		if (!$saveResult->isSuccess())
		{
			throw new SystemException($this->buildSaveErrorMessage(
				$saveResult->getErrorMessages(),
				'Failed to save document',
			));
		}

		$saved = $saveResult->getData()['document'] ?? null;
		if (!$saved instanceof Document)
		{
			throw new SystemException('Failed to save document');
		}

		// [P4.T1] Inherit derived rows from every active source covering the new node's branch.
		// Fail-closed: a skipped materialisation only under-grants (never over-shares), so it must
		// not turn a successful create into a failure — but it must not pass silently either. Without
		// the derived rows the node stays outside the shared subtree for good: the grantee never sees
		// it and whoever shared the branch has no way to notice. So the miss is logged and handed to
		// the durable agent, which converges the covering sources on its next tick.
		try
		{
			$this->subtreeAclReconciler->materialiseForNewNode((int)$saved->getId(), $collectionId);
		}
		catch (\Throwable $e)
		{
			$this->recoverMaterialisation((int)$saved->getId(), $collectionId, $e);
		}

		if ($contentFormat === DocumentTable::CONTENT_FORMAT_MD)
		{
			try
			{
				$this->searchIndexService->indexDocument((int)$saved->getId(), $markdown, $title);
			}
			catch (\Throwable)
			{
			}

			// [C1] Real markdown was written, so its outgoing links must enter the index here: only
			// setMarkdown() used to rebuild, but a document created straight with content (and every
			// import document, filled through update()) would otherwise stay linkless after backfill.
			// The rebuild absorbs its own failures, so create() keeps its contract.
			$this->documentLinkIndexService->rebuild((int)$saved->getId());
		}

		return $saved;
	}

	/**
	 * Updates an existing document. Returns null if document not found.
	 *
	 * @throws SystemException on validation or persistence failure
	 */
	public function update(
		int $id,
		?string $title,
		string|array|null $markdown,
		int $userId,
		?string $contentFormat = null,
		?array $referencedFileIds = null,
	): ?Document
	{
		$document = $this->repository->getById($id);
		if ($document === null)
		{
			return null;
		}

		if ($title !== null)
		{
			$document->setTitle($title);
		}

		if ($markdown !== null)
		{
			if (is_array($markdown))
			{
				$this->validateReferencedFileIds($markdown, $referencedFileIds);
			}
			$document->setMarkdown($markdown);
		}

		if ($contentFormat !== null)
		{
			$allowedFormats = [
				DocumentTable::CONTENT_FORMAT_JSON,
				DocumentTable::CONTENT_FORMAT_MD,
				DocumentTable::CONTENT_FORMAT_YJS,
			];
			if (!in_array($contentFormat, $allowedFormats, true))
			{
				throw new SystemException('Invalid document content format');
			}
			$document->setContentFormat($contentFormat);
		}
		elseif (is_array($markdown))
		{
			$document->setContentFormat(DocumentTable::CONTENT_FORMAT_JSON);
		}

		$document->setUpdatedBy($userId);

		// The format the row is saved with. One value drives both the dirty flag and the inline reindex
		// below, so the two cannot disagree about what this update left undone.
		$savedFormat = $document->getContentFormat();

		// Legacy json keeps an encoded TipTap tree in MARKDOWN, not text: the stripper would index its
		// punctuation and rebuild() refuses to parse it. Neither projection is derivable for that format —
		// by anyone, inline or in the background.
		$derivableFromMarkdown = $savedFormat !== DocumentTable::CONTENT_FORMAT_JSON;
		$searchIndexedInline =
			($savedFormat === DocumentTable::CONTENT_FORMAT_MD && (is_string($markdown) || $title !== null))
			|| ($savedFormat === DocumentTable::CONTENT_FORMAT_YJS && $title !== null)
		;
		$linksIndexedInline = is_string($markdown);

		// [P1.T4] Stamp the content date only when markdown is actually written (a title-only update does
		// not rebuild the text). The flag is the exact complement of the inline work: a projection this
		// path could have rebuilt but did not is handed off to the freshness agent ('Y').
		if ($markdown !== null)
		{
			$document->setContentUpdatedAt(new DateTime());
			$document->setDerivedStale($derivableFromMarkdown && !($searchIndexedInline && $linksIndexedInline));
		}
		$saveResult = $this->repository->save($document);
		if (!$saveResult->isSuccess())
		{
			throw new SystemException($this->buildSaveErrorMessage(
				$saveResult->getErrorMessages(),
				'Failed to save document',
			));
		}

		$saved = $saveResult->getData()['document'] ?? null;
		if (!$saved instanceof Document)
		{
			throw new SystemException('Failed to save document');
		}

		if ($searchIndexedInline)
		{
			try
			{
				$this->searchIndexService->indexDocument(
					(int)$saved->getId(),
					is_string($markdown) ? $markdown : null,
					$saved->getTitle(),
				);
			}
			catch (\Throwable)
			{
			}
		}

		// [C1] Whenever real markdown was persisted, bring the link index in step with it — the import
		// fills content through this path, so without it imported documents stay out of every backlink
		// list. A json (TipTap) payload is skipped inside rebuild(); a title-only update writes no
		// markdown and needs no rebuild.
		if (is_string($markdown))
		{
			$this->documentLinkIndexService->rebuild((int)$saved->getId());
		}

		return $saved;
	}

	/**
	 * Batch fetch by IDs. Returns map [id => row].
	 *
	 * @param int[] $ids
	 * @param string[] $select
	 * @return array<int, array<string, mixed>>
	 */
	public function findByIds(array $ids, array $select = ['ID', 'PARENT_ID', 'TITLE', 'MARKDOWN']): array
	{
		return $this->repository->getByIds($ids, $select);
	}

	public function setMarkdown(int $id, string $markdown): void
	{
		// [P1.T4] This path rebuilds only the link index below, not full-text search — so it records
		// the content build time but raises IS_DERIVED_STALE='Y' to hand search off to the freshness
		// agent. UPDATED_AT is intentionally left untouched (updatePartial does not move it).
		$this->repository->updatePartial($id, [
			'MARKDOWN' => $markdown,
			'CONTENT_UPDATED_AT' => new DateTime(),
			'IS_DERIVED_STALE' => DocumentTable::DERIVED_STALE_YES,
		]);

		// [P2.T4] Every direct write of content lands here — import steps, attachment back-patching,
		// mention reconciliation, install-time bootstrap — and none of them raises a content-settled
		// event. The one-shot agent that seeds the index has unregistered itself long before an
		// import runs, so without this call an imported knowledge base would never get links at all.
		// The rebuild absorbs its own failures, so setMarkdown keeps its contract.
		$this->documentLinkIndexService->rebuild($id);
	}

	public function clearYjsState(int $id): void
	{
		$this->repository->updatePartial($id, ['YJS_STATE' => null]);
	}

	/**
	 * System-level document creation without a user actor.
	 * CREATED_BY / UPDATED_BY are set to SystemUser::ID. No update-log row
	 * and no document-access work — caller relies on collection-level policy.
	 *
	 * Use only for module bootstrap (welcome content). Do NOT call from
	 * user-facing code — use create() instead.
	 *
	 * Returns the new document id or null on failure.
	 */
	public function createAsSystem(
		int $collectionId,
		?int $parentId,
		string $title,
		string $markdown,
		string $contentFormat = DocumentTable::CONTENT_FORMAT_MD,
	): ?int
	{
		if ($collectionId <= 0)
		{
			return null;
		}

		$position = $this->positionCalculator->calculateNextPosition(
			$this->repository->getMaxPosition($collectionId, $parentId)
		);

		$document = DocumentTable::createObject()
			->setCollectionId($collectionId)
			->setParentId($parentId)
			->setTitle($title)
			->setMarkdown($markdown)
			->setContentFormat($contentFormat)
			->setPosition($position)
			->setIsArchived(false)
			->setCreatedBy(SystemUser::ID)
			->setUpdatedBy(SystemUser::ID)
			// [P1.T4] createAsSystem() indexes both derived projections inline: record the build time,
			// keep the derived flag 'N'.
			->setContentUpdatedAt(new DateTime())
			->setDerivedStale(false)
		;

		$saveResult = $this->repository->save($document);
		if (!$saveResult->isSuccess())
		{
			return null;
		}

		$saved = $saveResult->getData()['document'] ?? null;
		if (!$saved instanceof Document)
		{
			return null;
		}

		$id = (int)$saved->getId();
		if ($id <= 0)
		{
			return null;
		}

		if ($contentFormat === DocumentTable::CONTENT_FORMAT_MD)
		{
			try
			{
				$this->searchIndexService->indexDocument($id, $markdown, $title);
			}
			catch (\Throwable)
			{
			}

			// [C1] Index the outgoing links of system-authored markdown (welcome content) too, so its
			// mentions become backlinks like any other document's.
			$this->documentLinkIndexService->rebuild($id);
		}

		return $id;
	}

	private function validateReferencedFileIds(array $markdown, ?array $referencedFileIds): void
	{
		$fileIds = is_array($referencedFileIds)
			? IdNormalizer::normalize($referencedFileIds)
			: $this->documentFileService->extractFileIds($markdown)
		;
		if (empty($fileIds))
		{
			return;
		}

		$validatedFileIdsMap = array_fill_keys(
			$this->documentFileService->getValidatedNoteFileIds($fileIds),
			true,
		);
		foreach ($fileIds as $fileId)
		{
			if (!isset($validatedFileIdsMap[$fileId]))
			{
				throw new SystemException('Document references an invalid file');
			}
		}
	}

	private function buildSaveErrorMessage(array $errorMessages, string $defaultMessage): string
	{
		return empty($errorMessages) ? $defaultMessage : implode(', ', $errorMessages);
	}

	/**
	 * [P4.T1] Records a failed inheritance materialisation and queues the covering sources for the
	 * durable reconcile agent, so an access hole on a freshly created node closes by itself.
	 * Best effort by construction: the create has already succeeded and must not be undone here.
	 */
	private function recoverMaterialisation(int $documentId, int $collectionId, \Throwable $error): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'NOTE_SUBTREE_MATERIALISE_FAILED',
			'MODULE_ID' => 'note',
			'ITEM_ID' => $documentId,
			'DESCRIPTION' => 'documentId=' . $documentId . ': ' . $error->getMessage(),
		]);

		try
		{
			$scheduler = new SubtreeAclReconcileScheduler();
			foreach ($this->subtreeAclReconciler->collectCoveringSourceIds($documentId) as $sourceId)
			{
				$scheduler->enqueue((int)$sourceId, $collectionId);
			}
		}
		catch (\Throwable)
		{
			// The miss is already logged; nothing else can be done on the request thread.
		}
	}

	/**
	 * @throws CollectionNotFoundException when the target collection does not exist.
	 */
	private function assertCollectionExists(int $collectionId): void
	{
		if (!$this->collectionRepository->exists($collectionId))
		{
			throw new CollectionNotFoundException();
		}
	}

	/**
	 * @throws ParentDocumentMismatchException when parent does not exist or lives in another collection.
	 */
	private function assertParentBelongsToCollection(?int $parentId, int $collectionId): void
	{
		if ($parentId === null || $parentId <= 0)
		{
			return;
		}

		$parent = $this->repository->getMetaById($parentId, ['ID', 'COLLECTION_ID']);
		if ($parent === null || (int)$parent->getCollectionId() !== $collectionId)
		{
			throw new ParentDocumentMismatchException();
		}
	}
}
