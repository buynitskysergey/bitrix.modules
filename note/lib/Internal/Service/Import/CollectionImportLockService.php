<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import;

use Bitrix\Main\Config\Option;
use Bitrix\Note\Infrastructure\Agent\Import\ImportAgent;
use Bitrix\Note\Internal\Repository\ImportMapRepository;
use Bitrix\Note\Internal\Repository\ImportSessionRepository;
use Bitrix\Note\Internal\Service\Import\Step\CreateCollectionStep;

/**
 * Detects whether a collection is currently the target of an active import session.
 * Used to block destructive operations (delete/etc.) that would race with ImportAgent
 * step execution and produce orphan rows.
 */
class CollectionImportLockService
{
	private const ACTIVE_STATUS = 'in_progress';

	public function __construct(
		private readonly ImportSessionRepository $sessionRepository = new ImportSessionRepository(),
		private readonly ImportMapRepository $mapRepository = new ImportMapRepository(),
	) {}

	/**
	 * Returns the id of an active import session targeting the given collection, or null.
	 *
	 * Truth sources, in order:
	 *   1. option.resultCollectionId — exact match for the collection currently being processed.
	 *   2. option.collectionIds[option.collectionIndex] -> import_map.findCollectionId() —
	 *      covers the short race window between AdvanceCollectionStep (sets
	 *      resultCollectionId=null, step='createCollection') and the next CreateCollectionStep
	 *      (sets resultCollectionId to the new id). Only consulted while step is
	 *      'createCollection' to avoid false positives from leftover map rows.
	 */
	public function findActiveSessionForCollection(int $collectionId): ?int
	{
		if ($collectionId <= 0)
		{
			return null;
		}

		foreach ($this->sessionRepository->listIdsByStatus(self::ACTIVE_STATUS) as $sessionId)
		{
			$option = $this->loadSessionOption($sessionId);
			if (empty($option))
			{
				continue;
			}

			$resultCollectionId = isset($option['resultCollectionId']) ? (int)$option['resultCollectionId'] : 0;
			if ($resultCollectionId === $collectionId)
			{
				return $sessionId;
			}

			if (
				$resultCollectionId === 0
				&& ($option['step'] ?? null) === 'createCollection'
				&& $this->matchesPendingCollection($option, $collectionId)
			)
			{
				return $sessionId;
			}
		}

		return null;
	}

	private function matchesPendingCollection(array $option, int $collectionId): bool
	{
		$collectionIds = $option['collectionIds'] ?? [];
		if (!is_array($collectionIds) || empty($collectionIds))
		{
			return false;
		}

		$index = (int)($option['collectionIndex'] ?? 0);
		$externalId = $collectionIds[$index] ?? null;
		if (!is_string($externalId) || $externalId === '')
		{
			return false;
		}

		$sourceType = $option['sourceType'] ?? null;
		if (!is_string($sourceType) || $sourceType === '')
		{
			return false;
		}

		// The state of a conversion holds the id of a base at its source; the map holds its own key
		// for it, and the two are not the same string for every source.
		$mapKey = CreateCollectionStep::collectionMapKey($sourceType, $externalId);

		return $this->mapRepository->findCollectionId($sourceType, $mapKey) === $collectionId;
	}

	private function loadSessionOption(int $sessionId): array
	{
		$raw = Option::get('main.stepper.note', ImportAgent::class . "({$sessionId})", '');
		if ($raw === '')
		{
			return [];
		}

		$data = unserialize($raw, ['allowed_classes' => false]);

		return is_array($data) ? $data : [];
	}
}
