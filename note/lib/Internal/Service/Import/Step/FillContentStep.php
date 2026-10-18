<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Step;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\ImportMapRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsStats;
use Bitrix\Note\Internal\Service\Document\DocumentService;
use Bitrix\Note\Internal\Service\Document\MainDocumentService;
use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Internal\Service\Import\Source\SourceInterface;
use Bitrix\Note\Internal\Service\Import\SourceReadableSnapshot;
use Bitrix\Note\Internal\Service\Import\Transformer\MdTransformerFactory;
use Bitrix\Note\Internal\Service\Import\Transformer\MentionTransformerFactory;
use Bitrix\Note\Internal\Service\Import\UnresolvedMentionService;

class FillContentStep implements StepInterface
{
	private const BATCH_SIZE = 100;

	public function __construct(
		private readonly DocumentService $documentService,
		private readonly UnresolvedMentionService $unresolvedMentionService,
		private readonly ImportMapRepository $mapRepository,
		private readonly MainDocumentService $mainDocumentService = new MainDocumentService(),
	)
	{
	}

	public function execute(array &$option, ?SourceInterface $source): void
	{
		$collectionIds = $option['collectionIds'];
		$collectionIndex = $option['collectionIndex'] ?? 0;
		$currentCollectionId = $collectionIds[$collectionIndex];
		$offset = $option['documentOffset'] ?? 0;
		$userId = (int)$option['userId'];

		$this->assertSourceReadable($option, (string)$currentCollectionId);

		$result = $source->getDocumentsPage($currentCollectionId, $offset, self::BATCH_SIZE);
		if (!$result->success)
		{
			throw new SystemException('Failed to fetch documents');
		}

		$documents = $result->data['documents'] ?? [];

		$mentionTransformer = MentionTransformerFactory::create(
			$option['sourceType'],
			$this->mapRepository,
			$option['sourceUrl'],
		);
		$mdTransformer = MdTransformerFactory::create($option['sourceType']);

		$externalIds = [];
		$markdownTexts = [];
		foreach ($documents as $doc)
		{
			$externalId = $doc['id'] ?? null;
			if ($externalId !== null)
			{
				$externalIds[] = $externalId;
			}
			$markdownTexts[] = $doc['text'] ?? '';
		}
		$mappings = $this->mapRepository->bulkLookup($option['sourceType'], $externalIds);

		// Backfill URL_ID for every doc in this batch before mention preload runs.
		// CreateStructureStep populates mappings from the tree endpoint, which doesn't always
		// expose urlId; documents.list does. Without this, links carrying slug-urlId form would
		// miss in import_map.URL_ID and degrade to fallback external links inside this same import.
		foreach ($documents as $doc)
		{
			$externalId = $doc['id'] ?? null;
			$urlId = $doc['urlId'] ?? null;
			$docId = $externalId !== null ? ($mappings[$externalId]['documentId'] ?? null) : null;
			if ($externalId !== null && $urlId !== null && $docId !== null)
			{
				$this->mapRepository->saveDocumentMapping(
					$option['sourceType'],
					$externalId,
					$docId,
					$urlId,
				);
			}
		}

		// Прогрев кеша mention-резолва: один bulkLookup на все mentions всех документов batch-а.
		$mentionTransformer->preload($markdownTexts);

		$processedDocIds = [];
		$unresolvedRowsToInsert = [];

		foreach ($documents as $doc)
		{
			try
			{
				$externalId = $doc['id'] ?? null;
				$title = ($doc['title'] ?? '') !== '' ? $doc['title'] : 'Untitled';
				$text = $doc['text'] ?? '';

				if ($externalId === null)
				{
					continue;
				}

				// A source page the source declares to be the description of the whole base
				// (the home page of a landing knowledge base) has no document of its own.
				$isDescription = !empty($doc['isCollectionDescription']);

				$docId = $isDescription
					? $this->resolveDescriptionDocument($option, $externalId, $doc['urlId'] ?? null, $userId)
					: $mappings[$externalId]['documentId'] ?? null
				;

				if ($docId === null && !$isDescription)
				{
					$docId = $this->createMissingDocument(
						$option,
						$externalId,
						$doc['urlId'] ?? null,
						$title,
						$userId,
					);
				}

				if ($docId === null)
				{
					continue;
				}

				$mentionResult = $mentionTransformer->transform($text);
				$transformedText = $mentionResult->markdown !== '' ? $mentionResult->markdown : "\n";
				$transformedText = $mdTransformer->preprocessMarkdown($transformedText);
				if ($transformedText === '' || $transformedText === null)
				{
					$transformedText = "\n";
				}

				$this->documentService->update(
					id: $docId,
					// The main document keeps its own title: it names the description of the base
					// for every reader, while the source page title names just that page.
					title: $isDescription ? null : $title,
					markdown: $transformedText,
					userId: $userId,
					contentFormat: DocumentTable::CONTENT_FORMAT_MD,
				);

				$this->documentService->clearYjsState($docId);

				$processedDocIds[] = $docId;

				$option['doneCount'] = ($option['doneCount'] ?? 0) + 1;
				$option['totalAttachments'] = ($option['totalAttachments'] ?? 0)
					+ count($mdTransformer->extractAttachmentIds($transformedText));

				foreach ($mentionResult->unresolvedIds as $unresolvedId)
				{
					$unresolvedRowsToInsert[] = [
						'DOCUMENT_ID' => $docId,
						'SOURCE_TYPE' => $option['sourceType'],
						'EXTERNAL_ID' => $unresolvedId,
					];
				}

				// Exactly one create_document marker per imported source page (success outcome).
				// Emitted last in try so a throw earlier routes solely to catch (no double marker).
				AnalyticsService::documentCreated(
					true,
					AnalyticsStats::buildDocumentImportStats((string)$option['sourceType']),
					AnalyticsDictionary::TYPE_IMPORT,
				);
			}
			catch (\Throwable $e)
			{
				$docTitle = $doc['title'] ?? ($doc['id'] ?? '');
				ImportLogger::logError("fillContent error [{$docTitle}]: " . $e->getMessage());
				ImportLogger::addErrorDetail($option, $docTitle, ImportLogger::resolveErrorReason($e));

				// Error outcome for the same source page (still exactly one marker per page).
				AnalyticsService::documentCreated(
					false,
					AnalyticsStats::buildDocumentImportStats((string)$option['sourceType']),
					AnalyticsDictionary::TYPE_IMPORT,
				);
			}
		}

		if (!empty($processedDocIds))
		{
			$this->unresolvedMentionService->deleteByDocumentIds($processedDocIds);
		}

		if (!empty($unresolvedRowsToInsert))
		{
			$this->unresolvedMentionService->addBatch($unresolvedRowsToInsert);
		}

		$itemsRead = $this->sourceItemsRead($result->data, count($documents));
		$option['documentOffset'] = $offset + $itemsRead;

		if ($itemsRead < $this->sourceWindow($result->data))
		{
			$option['step'] = 'downloadAttachments';
			$option['attachmentDocIndex'] = 0;
			$option['attachmentFileIndex'] = 0;
		}
	}

	/**
	 * How far down its own listing the source got in this batch - the number the window moves by and
	 * the one that says whether the source is exhausted. It is not the number of documents the batch
	 * produced: an element the source listed but could not read afterwards yields no document, and
	 * counting documents instead would move the window back onto elements already imported and take
	 * the short batch for the end of the source, leaving the rest of it unread without a word.
	 *
	 * A source that states nothing is paged by the documents it returned, as before: for such a
	 * source a batch is short only when there is nothing left to give.
	 */
	private function sourceItemsRead(array $data, int $documentCount): int
	{
		return (int)($data['consumed'] ?? $documentCount);
	}

	/**
	 * The window the source answered with, which is the length a full batch has - and so the number a
	 * short batch, the end of the source, is short against. A source with a page ceiling of its own
	 * hands out less than {@see self::BATCH_SIZE} and says so; measured against the size asked for, its every
	 * batch would be short, and the source would be called finished after the first one.
	 *
	 * A source that names no window of its own was given the whole one it was asked for.
	 */
	private function sourceWindow(array $data): int
	{
		$window = (int)($data['windowLimit'] ?? self::BATCH_SIZE);

		return $window > 0 ? $window : self::BATCH_SIZE;
	}

	/**
	 * Refuses to read the content of a base the initiator could not read when they started the
	 * conversion (ALG-03). A background job cannot ask landing about their rights - it would be told
	 * about whoever is logged in - so the snapshot their own request left behind is the only source of
	 * truth here. The request refuses such a conversion before a session exists
	 * ({@see \Bitrix\Note\Public\Command\Import\StartImportCommand}); this is the line for a state
	 * that names such a base anyway.
	 */
	private function assertSourceReadable(array $option, string $sourceCollectionId): void
	{
		if (SourceReadableSnapshot::allows($option, $sourceCollectionId))
		{
			return;
		}

		$userId = (int)($option['userId'] ?? 0);
		ImportLogger::logError(
			"fillContent: {$sourceCollectionId} was not readable by user {$userId} when the "
			. 'conversion started - nothing of it is read'
		);

		throw new SystemException('Source collection is not readable by the initiator');
	}

	/**
	 * The document that carries the knowledge base description: the collection's main document.
	 * As a rule it already exists - it is created together with the collection - but a repeat
	 * import into a collection older than the main document itself, one the backfill agent has not
	 * reached yet, would otherwise have no receiver and lose the home page content silently.
	 * Creation is idempotent, so an existing document is returned as is and never duplicated.
	 * The author is the initiator of the import, taken from the step state rather than from the
	 * current user: the steps run in a background job, outside the initiator's session.
	 *
	 * The mapping of the source page is written onto it: the page becomes no document of its own,
	 * and without the mapping the address it used to live at would resolve to nothing.
	 * The description also joins the imported ids, because its attachments are downloaded by the
	 * same step that walks that list.
	 */
	private function resolveDescriptionDocument(
		array &$option,
		string $externalId,
		?string $urlId,
		int $userId,
	): ?int
	{
		$collectionId = (int)($option['resultCollectionId'] ?? 0);
		$documentId = $collectionId > 0
			? $this->mainDocumentService->ensureMainDocument($collectionId, $userId)
			: null
		;

		if ($documentId === null)
		{
			ImportLogger::logError(
				"fillContent: no main document in collection {$collectionId} to describe externalId={$externalId}"
			);

			return null;
		}

		$this->mapRepository->saveDocumentMapping($option['sourceType'], $externalId, $documentId, $urlId);
		$option['importedDocIds'][] = $documentId;

		return $documentId;
	}

	/**
	 * Recreates a document that has no mapping yet (e.g. deleted between the structure and the
	 * content step). The anchor is written together with the key: every converted element must be
	 * reachable from its source address, and a document restored here is no exception - without it
	 * the element drops out of the reverse links from the source.
	 */
	private function createMissingDocument(
		array &$option,
		string $externalId,
		?string $urlId,
		string $title,
		int $userId,
	): ?int
	{
		$collectionId = $option['resultCollectionId'] ?? null;
		if ($collectionId === null)
		{
			ImportLogger::logError("fillContent: no resultCollectionId for missing doc externalId={$externalId}");

			return null;
		}

		ImportLogger::logInfo("fillContent: creating missing document [{$title}] externalId={$externalId}");

		try
		{
			$savedDoc = $this->documentService->create(
				collectionId: $collectionId,
				parentId: null,
				title: $title,
				markdown: "\n",
				userId: $userId,
				contentFormat: DocumentTable::CONTENT_FORMAT_MD,
			);

			$docId = (int)$savedDoc->getId();
			$this->mapRepository->saveDocumentMapping($option['sourceType'], $externalId, $docId, $urlId);
			$option['importedDocIds'][] = $docId;

			return $docId;
		}
		catch (\Throwable $e)
		{
			ImportLogger::logError("fillContent: failed to create missing doc [{$title}]: " . $e->getMessage());
			ImportLogger::addErrorDetail($option, $title, ImportLogger::resolveErrorReason($e));

			return null;
		}
	}
}
