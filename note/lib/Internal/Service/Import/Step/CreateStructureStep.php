<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Step;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\ImportMapRepository;
use Bitrix\Note\Internal\Service\Document\DocumentService;
use Bitrix\Note\Internal\Service\Document\Position\PositionService;
use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Internal\Service\Import\Source\SourceInterface;
use Bitrix\Note\Internal\Service\Import\SourceReadableSnapshot;

class CreateStructureStep implements StepInterface
{
	private const BATCH_SIZE = 100;

	public function __construct(
		private readonly DocumentService $documentService,
		private readonly PositionService $positionService,
		private readonly ImportMapRepository $mapRepository,
	)
	{
	}

	public function execute(array &$option, ?SourceInterface $source): void
	{
		if (!isset($option['structureQueue']))
		{
			$collectionIds = $option['collectionIds'];
			$collectionIndex = $option['collectionIndex'] ?? 0;
			$currentCollectionId = $collectionIds[$collectionIndex];

			$this->assertSourceReadable($option, (string)$currentCollectionId);

			$result = $source->getDocumentTree($currentCollectionId);
			if (!$result->success)
			{
				throw new SystemException('Failed to fetch document tree');
			}

			$queue = $this->flattenTree($result->data['documents'] ?? []);
			$option['structureQueue'] = $queue;
			$option['structureIndex'] = 0;
			$option['totalItems'] = count($queue);

			ImportLogger::logInfo("createStructure: queued " . count($queue) . " documents");
		}

		$queue = $option['structureQueue'];
		$index = $option['structureIndex'] ?? 0;
		$end = min($index + self::BATCH_SIZE, count($queue));
		$userId = (int)$option['userId'];
		$collectionId = $option['resultCollectionId'];
		$overwrite = $option['overwrite'] ?? false;

		$existingMappings = [];
		if ($overwrite)
		{
			$batchExternalIds = [];
			for ($i = $index; $i < $end; $i++)
			{
				$batchExternalIds[] = $queue[$i]['id'];
			}
			$existingMappings = $this->mapRepository->bulkLookup($option['sourceType'], $batchExternalIds);
		}

		$parentExternalIds = [];
		for ($i = $index; $i < $end; $i++)
		{
			$parentExternalId = $queue[$i]['parentExternalId'] ?? null;
			if ($parentExternalId !== null)
			{
				$parentExternalIds[] = $parentExternalId;
			}
		}
		$parentMappings =
			!empty($parentExternalIds)
				? $this->mapRepository->bulkLookup($option['sourceType'], array_values(array_unique($parentExternalIds)))
				: []
		;

		$existingParentIdMap = [];
		$existingCollectionIdMap = [];
		if ($overwrite)
		{
			$existingDocIds = [];
			foreach ($existingMappings as $mapping)
			{
				if ($mapping['documentId'] !== null)
				{
					$existingDocIds[] = $mapping['documentId'];
				}
			}
			if (!empty($existingDocIds))
			{
				$rows = $this->documentService->findByIds($existingDocIds, ['ID', 'PARENT_ID', 'COLLECTION_ID']);
				foreach ($rows as $id => $row)
				{
					$existingParentIdMap[$id] = $row['PARENT_ID'] !== null ? (int)$row['PARENT_ID'] : null;
					$existingCollectionIdMap[$id] = $row['COLLECTION_ID'] !== null ? (int)$row['COLLECTION_ID'] : null;
				}
			}
		}

		$localMap = [];

		for ($i = $index; $i < $end; $i++)
		{
			$entry = $queue[$i];
			$title = ($entry['title'] ?? '') !== '' ? $entry['title'] : 'Untitled';

			try
			{
				$externalId = $entry['id'];
				$parentExternalId = $entry['parentExternalId'];

				$parentId = null;
				if ($parentExternalId !== null)
				{
					$parentId = $localMap[$parentExternalId]
						?? ($parentMappings[$parentExternalId]['documentId'] ?? null);
				}

				$existingDocId = $existingMappings[$externalId]['documentId'] ?? null;

				// A mapped document can be updated in place only if it still exists AND
				// already lives in the target collection. If it's gone, or stranded in a
				// different / deleted collection (deleting a collection in the UI orphans
				// its documents — they keep the old COLLECTION_ID — without clearing the
				// import map), its position branch is invalid and a move would fail with
				// "Document not found in source branch". In that case recreate it fresh in
				// the target collection so the re-import doesn't end up empty.
				$canReuse = $existingDocId !== null
					&& array_key_exists($existingDocId, $existingCollectionIdMap)
					&& ($existingCollectionIdMap[$existingDocId] ?? null) === $collectionId;

				if ($canReuse)
				{
					try
					{
						$this->documentService->update(
							id: $existingDocId,
							title: $title,
							markdown: null,
							userId: $userId,
							contentFormat: DocumentTable::CONTENT_FORMAT_MD,
						);
					}
					catch (\Throwable $e)
					{
						ImportLogger::logError("createStructure update error [{$title}]: " . $e->getMessage());
						ImportLogger::addErrorDetail($option, $title, Loc::getMessage('NOTE_IMPORT_ERROR_DOC_STRUCTURE_SAVE_FAILED'));

						continue;
					}

					try
					{
						// Same collection is guaranteed here; only the parent may differ.
						$currentParentId = $existingParentIdMap[$existingDocId] ?? null;
						if ($currentParentId !== $parentId)
						{
							$this->positionService->move(
								documentId: $existingDocId,
								targetCollectionId: $collectionId,
								targetParentId: $parentId,
								targetPosition: null,
								userId: $userId,
							);
						}
					}
					catch (\Throwable $e)
					{
						ImportLogger::logError("createStructure move error [{$title}]: " . $e->getMessage());
						ImportLogger::addErrorDetail($option, $title, Loc::getMessage('NOTE_IMPORT_ERROR_DOC_STRUCTURE_MOVE_FAILED'));
					}

					$docId = $existingDocId;
				}
				else
				{
					$savedDoc = $this->documentService->create(
						collectionId: $collectionId,
						parentId: $parentId,
						title: $title,
						markdown: "\n",
						userId: $userId,
						contentFormat: DocumentTable::CONTENT_FORMAT_MD,
					);

					$docId = (int)$savedDoc->getId();
					$this->mapRepository->saveDocumentMapping(
						$option['sourceType'],
						$externalId,
						$docId,
						$entry['urlId'] ?? null,
					);
				}

				$localMap[$externalId] = $docId;
				$option['importedDocIds'][] = $docId;
			}
			catch (\Throwable $e)
			{
				ImportLogger::logError("createStructure error [{$title}]: " . $e->getMessage());
				ImportLogger::addErrorDetail($option, $title, ImportLogger::resolveErrorReason($e));
			}
		}

		$option['structureIndex'] = $end;

		if ($end >= count($queue))
		{
			unset($option['structureQueue'], $option['structureIndex']);
			$option['step'] = 'fillContent';
			$option['documentOffset'] = 0;
		}
	}

	/**
	 * Refuses to read the structure of a base the initiator could not read when they started the
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
			"createStructure: {$sourceCollectionId} was not readable by user {$userId} when the "
			. 'conversion started - nothing of it is read'
		);

		throw new SystemException('Source collection is not readable by the initiator');
	}

	private function flattenTree(array $nodes, ?string $parentExternalId = null): array
	{
		$result = [];
		$reversed = array_reverse($nodes);

		foreach ($reversed as $node)
		{
			$result[] = [
				'id' => $node['id'],
				'urlId' => $node['urlId'] ?? null,
				'title' => ($node['title'] ?? '') !== '' ? $node['title'] : 'Untitled',
				'parentExternalId' => $parentExternalId,
			];

			if (!empty($node['children']))
			{
				array_push($result, ...$this->flattenTree($node['children'], $node['id']));
			}
		}

		return $result;
	}
}
