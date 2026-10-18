<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Step;

use Bitrix\Main\SystemException;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\ImportMapRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsStats;
use Bitrix\Note\Internal\Service\Collection\CollectionService;
use Bitrix\Note\Internal\Service\Import\CollectionAccessTransfer;
use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Internal\Service\Import\Source\Landing\LandingId;
use Bitrix\Note\Internal\Service\Import\Source\SourceInterface;

class CreateCollectionStep implements StepInterface
{
	private const LANDING_SOURCE_TYPE = 'landing';

	private CollectionAccessTransfer $accessTransfer;

	public function __construct(
		private readonly CollectionService $collectionService,
		private readonly ImportMapRepository $mapRepository,
		?CollectionAccessTransfer $accessTransfer = null,
	)
	{
		$this->accessTransfer = $accessTransfer ?? new CollectionAccessTransfer();
	}

	public function execute(array &$option, ?SourceInterface $source): void
	{
		$collectionIds = $option['collectionIds'];
		$collectionIndex = $option['collectionIndex'] ?? 0;
		$currentCollectionId = $collectionIds[$collectionIndex];

		if (!isset($option['collectionNames']))
		{
			$collectionsResult = $source->getCollections();
			$names = [];
			$urlIds = [];
			foreach ($collectionsResult->data['collections'] ?? [] as $c)
			{
				$names[$c['id']] = $c['name'];
				if (!empty($c['urlId']))
				{
					$urlIds[$c['id']] = $c['urlId'];
				}
			}
			$option['collectionNames'] = $names;
			$option['collectionUrlIds'] = $urlIds;
		}

		$collectionName = $option['collectionNames'][$currentCollectionId] ?? $currentCollectionId;
		$collectionUrlId = $option['collectionUrlIds'][$currentCollectionId] ?? null;
		$userId = (int)$option['userId'];
		$overwrite = $option['overwrite'] ?? false;
		$sourceType = (string)$option['sourceType'];
		$mapKey = self::collectionMapKey($sourceType, (string)$currentCollectionId);

		$mappedCollectionId = $overwrite
			? $this->mapRepository->findCollectionId($sourceType, $mapKey)
			: null;

		$updateWithheld = $mappedCollectionId !== null
			&& !$this->mayUpdateEarlierConversion($sourceType, $mappedCollectionId, $userId, (string)$currentCollectionId);
		$existingCollectionId = $updateWithheld ? null : $mappedCollectionId;

		if ($existingCollectionId !== null)
		{
			$this->collectionService->update($existingCollectionId, $collectionName, $userId);
			$option['resultCollectionId'] = $existingCollectionId;
			ImportLogger::logInfo("createCollection: reusing {$existingCollectionId} ({$collectionName})");
		}
		else
		{
			$collection = $this->collectionService->create($collectionName, $userId);
			if ($collection === null)
			{
				throw new SystemException('Failed to create collection: ' . $collectionName);
			}

			$option['resultCollectionId'] = (int)$collection->getId();
			// A withheld update leaves the mapping where it is: it says which base of note a base of the
			// source became, and rewriting it would hand that identity to a copy. That holds for the base
			// of a user who was just told they may not touch the original, and for an archived one just
			// as much - an archive can be restored, and it has to come back as itself.
			if (!$updateWithheld)
			{
				$this->mapRepository->saveCollectionMapping(
					$sourceType,
					$mapKey,
					$option['resultCollectionId'],
					$collectionUrlId,
				);
			}
			ImportLogger::logInfo("createCollection: created {$option['resultCollectionId']} ({$collectionName})");

			// Per-collection import marker.
			AnalyticsService::collectionCreated(
				true,
				AnalyticsStats::buildCollectionStats(1, 0, 0, $sourceType),
				AnalyticsDictionary::TYPE_IMPORT,
			);
		}

		if ($sourceType === self::LANDING_SOURCE_TYPE && $existingCollectionId === null)
		{
			// The initiator moderates the base they converted, and nobody else does by way of the
			// source: the access landing carries is transferred right below, but DTO-01 never derives
			// the level of a moderator from it. Asserted for a base this conversion makes and for no
			// other: whether the initiator may update one that was already there is answered above, and
			// an initiator who got through that question by another route - a portal administrator, a
			// moderator by way of a group - is not owed a level of their own on the base of somebody
			// else, the less so one that outlives the route it came from.
			try
			{
				$grant = CollectionAccessService::grantUserLevel(
					(int)$option['resultCollectionId'],
					$userId,
					CollectionAccessService::LEVEL_MODERATE,
					$userId,
				);
				if (!$grant->isSuccess())
				{
					foreach ($grant->getErrors() as $error)
					{
						ImportLogger::logError('createCollection: landing initiator grant failed - ' . $error->getMessage());
					}
				}
			}
			catch (\Throwable $e)
			{
				ImportLogger::logError('createCollection: landing initiator grant threw - ' . $e->getMessage());
			}
		}

		// Reproduce the source base's access on the base the conversion made (best-effort; no-op for
		// sources without source-side permissions, e.g. Outline). Never onto a base that was already
		// there: the transfer replaces the whole of the access of a collection, and what a base of note
		// holds by then was given in note, by whoever moderates it there, while the update was offered
		// as the content of the source afresh and said nothing about rights. The rule stands on the
		// landing branch alone - the update of the other sources is left as it was.
		if ($existingCollectionId === null || $sourceType !== self::LANDING_SOURCE_TYPE)
		{
			$this->accessTransfer->apply($option, $source);
		}

		$option['step'] = 'createStructure';
		$option['importedDocIds'] = [];
		$option['documentOffset'] = 0;
		unset($option['structureQueue'], $option['structureIndex']);
	}

	/**
	 * The key the map holds a base under, out of the id the conversion carries for that base.
	 *
	 * The two are one string for every source but landing, where the id of a base is the CODE of its
	 * site: that CODE is what the source and the transport find the site by, and landing lets it grow
	 * past the column the map writes into, so the map gets a key cut to fit
	 * ({@see LandingId::collectionExternalId}) while everything else keeps the CODE whole.
	 *
	 * Asked in one place because a mapping written under one key and looked up under another is a
	 * base converted twice: the repeat is not recognised as one, and the user gets a second base
	 * instead of the update they asked for.
	 */
	public static function collectionMapKey(string $sourceType, string $collectionId): string
	{
		return $sourceType === self::LANDING_SOURCE_TYPE
			? LandingId::collectionExternalId($collectionId)
			: $collectionId
		;
	}

	/**
	 * Whether the update aimed at the base an earlier conversion of $externalId left behind goes ahead,
	 * with the reason of a refusal written to the log: both reasons end the same way - a base of its own,
	 * with the mapping left alone - and are told apart nowhere else.
	 */
	private function mayUpdateEarlierConversion(
		string $sourceType,
		int $collectionId,
		int $userId,
		string $externalId,
	): bool
	{
		if (!self::countsAsEarlierConversion($sourceType, $collectionId))
		{
			ImportLogger::logInfo(
				"createCollection: collection {$collectionId}, which {$externalId} was converted into "
				. 'before, is archived or gone - a new base is created instead'
			);

			return false;
		}

		if (!self::mayInitiatorUpdate($sourceType, $collectionId, $userId))
		{
			ImportLogger::logError(
				"createCollection: user {$userId} may not moderate collection {$collectionId}, "
				. "which {$externalId} was converted into before - a new base is created instead"
			);

			return false;
		}

		return true;
	}

	/**
	 * Whether the collection a mapping names still counts as the base an earlier conversion of
	 * $sourceType left behind.
	 *
	 * An archived one does not, on the landing branch: the answer the repeat-import screen is built from
	 * lists live collections alone (API-01), so the screen never named it. Updating it would rewrite an
	 * archive the user was told nothing about, and refusing the conversion over it - which is what the
	 * right of a moderator decides once the base is taken for an earlier conversion - would name a base
	 * they cannot see. Neither happens: the conversion makes a base of its own, and the mapping stays
	 * with the archived one, which can be restored and has to come back as itself.
	 *
	 * A collection that is gone answers the same way and for the same reason: the screen has nothing to
	 * show for it either.
	 *
	 * The rule stands on the landing branch alone - Outline and the local wiki keep the base they had.
	 */
	public static function countsAsEarlierConversion(string $sourceType, int $collectionId): bool
	{
		if ($sourceType !== self::LANDING_SOURCE_TYPE)
		{
			return true;
		}

		$collection = (new CollectionRepository())->getById($collectionId);

		return $collection !== null && !$collection->getIsArchived();
	}

	/**
	 * Whether the initiator may update the base an earlier conversion of $sourceType left behind
	 * (API-02). The mapping is looked up by source and external key alone, with no owner in it, so the
	 * collection it names can belong to anybody: updating one without the right to moderate it renames
	 * and rewrites the base of another user.
	 *
	 * The right is read for the initiator held in the state of the conversion and never for whoever
	 * happens to be logged in, because a step runs in a background job (ALG-03). The same question is
	 * put in the request the conversion is started from
	 * ({@see \Bitrix\Note\Public\Command\Import\StartImportCommand}), so the refusal reaches the user
	 * before anything is created; this is the last line, for the right taken away in between.
	 *
	 * The rule stands on the landing branch alone - the update of the other sources is left as it was.
	 */
	public static function mayInitiatorUpdate(string $sourceType, int $collectionId, int $userId): bool
	{
		if ($sourceType !== self::LANDING_SOURCE_TYPE)
		{
			return true;
		}

		if ($collectionId <= 0 || $userId <= 0)
		{
			return false;
		}

		if (PortalAdmin::isAdmin($userId))
		{
			return true;
		}

		return CollectionAccessService::hasCollectionLevel(
			$collectionId,
			$userId,
			CollectionAccessService::buildUserAccessCodes($userId),
			CollectionAccessService::LEVEL_MODERATE,
		);
	}
}
