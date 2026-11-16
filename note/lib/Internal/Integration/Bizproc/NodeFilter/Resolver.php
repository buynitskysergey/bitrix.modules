<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc\NodeFilter;

use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Note\Internal\Integration\Bizproc\Document\NoteDocument;
use Bitrix\Note\Internal\Repository\DocumentRepository;

final class Resolver
{
	private const TARGET_FILTER_ID_PROPERTY = 'TargetFilterId';
	private const FILTER_SETTINGS_PROPERTY = 'FilterSettings';
	private const SOURCE_MODE_WORKFLOW = 'workflow';
	private const SOURCE_MODE_FILTER = 'filter';
	private const RESOLUTION_LIMIT = 150;
	private const MAX_FILTERS = 20;

	private static ?\WeakMap $resolutionCache = null;

	public static function resolveDocuments(\CBPActivity $activity): array
	{
		$settings = self::getFilterSettings($activity);
		if ($settings === [])
		{
			return [];
		}

		self::forgetResolutionOfPreviousEntry($activity, $settings);

		return self::resolveDocumentsBySettings($activity, $settings);
	}

	public static function hasTargetFilterId(\CBPActivity $activity): bool
	{
		return self::resolveTargetFilterId($activity) !== null;
	}

	public static function resolveDocumentId(\CBPActivity $activity): ?array
	{
		$targetFilterId = self::resolveTargetFilterId($activity);
		if ($targetFilterId === null)
		{
			return null;
		}

		$documentIdFromParent = self::findDocumentIdInParentProperties($activity, $targetFilterId);
		if ($documentIdFromParent !== null)
		{
			return $documentIdFromParent;
		}

		$settings = self::getFilterSettings($activity);
		if ($settings === [])
		{
			return null;
		}

		$resolved = self::resolveDocumentsBySettings($activity, $settings);

		return $resolved[$targetFilterId]['documentId'] ?? null;
	}

	private static function resolveTargetFilterId(\CBPActivity $activity): ?string
	{
		$filterId = trim((string)($activity->{self::TARGET_FILTER_ID_PROPERTY} ?? ''));

		return $filterId !== '' ? $filterId : null;
	}

	private static function findDocumentIdInParentProperties(\CBPActivity $activity, string $filterId): ?array
	{
		for ($parent = $activity->parent; $parent !== null; $parent = $parent->parent)
		{
			$documentId = $parent->{$filterId} ?? null;
			if (is_array($documentId) && $documentId !== [])
			{
				return $documentId;
			}
		}

		return null;
	}

	private static function getFilterSettings(\CBPActivity $activity): array
	{
		for ($current = $activity; $current !== null; $current = $current->parent)
		{
			$settings = $current->{self::FILTER_SETTINGS_PROPERTY} ?? null;
			if (is_array($settings) && $settings !== [])
			{
				return self::normalizeSettings($settings);
			}
		}

		return [];
	}

	private static function normalizeSettings(array $settings): array
	{
		$normalized = [];

		foreach ($settings as $index => $filter)
		{
			if (count($normalized) >= self::MAX_FILTERS)
			{
				break;
			}

			$normalizedFilter = self::normalizeFilter($filter, (int)$index);
			if ($normalizedFilter !== null && !isset($normalized[$normalizedFilter['id']]))
			{
				$normalized[$normalizedFilter['id']] = $normalizedFilter;
			}
		}

		foreach ($normalized as $id => $filter)
		{
			if (
				$filter['sourceMode'] === self::SOURCE_MODE_FILTER
				&& !isset($normalized[$filter['sourceFilterId']])
			)
			{
				unset($normalized[$id]);
			}
		}

		return array_values($normalized);
	}

	private static function normalizeFilter(mixed $filter, int $index): ?array
	{
		if (!is_array($filter))
		{
			return null;
		}

		$sourceMode = (string)($filter['sourceMode'] ?? self::SOURCE_MODE_WORKFLOW);
		if (!in_array($sourceMode, [self::SOURCE_MODE_WORKFLOW, self::SOURCE_MODE_FILTER], true))
		{
			return null;
		}

		$sourceFilterId = trim((string)($filter['sourceFilterId'] ?? ''));
		if ($sourceMode === self::SOURCE_MODE_FILTER && $sourceFilterId === '')
		{
			return null;
		}

		return [
			'id' => (string)($filter['id'] ?? ('filter_' . $index)),
			'sourceMode' => $sourceMode,
			'sourceFilterId' => $sourceFilterId,
			'conditions' => is_array($filter['conditions'] ?? null) ? $filter['conditions'] : ['items' => []],
		];
	}

	private static function forgetResolutionOfPreviousEntry(\CBPActivity $activity, array $settings): void
	{
		$root = $activity->getRootActivity();

		$cachedByRoot = self::getCachedResolutions($root);
		unset($cachedByRoot[self::getSettingsKey($settings)]);

		self::$resolutionCache[$root] = $cachedByRoot;
	}

	private static function getCachedResolutions(\CBPActivity $root): array
	{
		self::$resolutionCache ??= new \WeakMap();

		return self::$resolutionCache[$root] ?? [];
	}

	private static function getSettingsKey(array $settings): string
	{
		return md5(serialize($settings));
	}

	private static function resolveDocumentsBySettings(\CBPActivity $activity, array $settings): array
	{
		$root = $activity->getRootActivity();
		$settingsKey = self::getSettingsKey($settings);

		$cachedByRoot = self::getCachedResolutions($root);
		if (array_key_exists($settingsKey, $cachedByRoot))
		{
			return $cachedByRoot[$settingsKey];
		}

		$resolved = [];

		foreach ($settings as $filter)
		{
			$result = self::resolveFilter($activity, $filter, $resolved);
			if ($result !== null)
			{
				$resolved[$filter['id']] = $result;
			}
		}

		$cachedByRoot[$settingsKey] = $resolved;
		self::$resolutionCache[$root] = $cachedByRoot;

		return $resolved;
	}

	private static function resolveFilter(\CBPActivity $activity, array $filter, array $resolved): ?array
	{
		$candidateIds = self::resolveCandidateIds($activity, $filter, $resolved);
		if (is_array($candidateIds) && $candidateIds === [])
		{
			return null;
		}

		$conditionsFilter = [];
		if (!empty($filter['conditions']['items']))
		{
			$conditionsFilter = self::getConditionsFilter($filter['conditions']);
			if (!self::hasPredicates($conditionsFilter))
			{
				return null;
			}
		}

		$liveIds = (new DocumentRepository())->findLiveIdsByFilter(
			$conditionsFilter,
			$candidateIds,
			self::RESOLUTION_LIMIT,
		);

		if ($liveIds === [])
		{
			return null;
		}

		$documentIds = array_map(
			static fn(int $documentId): array => NoteDocument::complexDocumentId($documentId),
			$liveIds,
		);

		return [
			'documentId' => $documentIds[0],
			'documentIds' => $documentIds,
			'entityIds' => $liveIds,
		];
	}

	private static function resolveCandidateIds(\CBPActivity $activity, array $filter, array $resolved): ?array
	{
		if (($filter['sourceMode'] ?? self::SOURCE_MODE_WORKFLOW) === self::SOURCE_MODE_FILTER)
		{
			return $resolved[$filter['sourceFilterId']]['entityIds'] ?? [];
		}

		if (!empty($filter['conditions']['items']))
		{
			return null;
		}

		$documentId = self::extractDocumentId($activity->getRootActivity()->getDocumentId());

		return $documentId === null ? [] : [$documentId];
	}

	private static function extractDocumentId(mixed $documentId): ?int
	{
		if (!is_array($documentId) || ($documentId[0] ?? null) !== NoteDocument::MODULE_ID)
		{
			return null;
		}

		$id = (int)($documentId[2] ?? 0);

		return $id > 0 ? $id : null;
	}

	private static function getConditionsFilter(array $conditions): array
	{
		$documentType = NoteDocument::complexDocumentType();

		$conditionGroup = new ConditionGroup($conditions);
		$conditionGroup->internalizeValues($documentType);

		return ServiceLocator::getInstance()
			->get('bizproc.service.activity.entityFilter')
			->getOrmFilter(
				$conditionGroup,
				$documentType,
				$documentType,
				FilterFields::mapForDocumentType($documentType),
			)
		;
	}

	private static function hasPredicates(array $ormFilter): bool
	{
		foreach ($ormFilter as $key => $group)
		{
			if ($key === 'LOGIC')
			{
				continue;
			}

			if (is_array($group) && $group !== [])
			{
				return true;
			}
		}

		return false;
	}
}
