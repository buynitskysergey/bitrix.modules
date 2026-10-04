<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\Complex\ActionClassification;
use Bitrix\Bizproc\Activity\Dto\Complex\ActionObject;
use Bitrix\Bizproc\Activity\Enum\ActionArea;
use Bitrix\Bizproc\Activity\Enum\ActionGroup;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Activities;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Main\ModuleManager;
use Psr\Log\LoggerInterface;

/**
 * Collector of NODE_ACTION classification declarations.
 *
 * Traverses every NODE_ACTION activity via the Searcher, reads its self-describing
 * NodeActionSettings and builds an ActionClassification per activity. Fail-closed: an
 * activity without a complete classification (missing group or area) is skipped and kept
 * in flat mode; a partial declaration is logged. Declared objects are cross-checked against
 * the activity FILTER so only executable combinations survive.
 *
 * The folder traversal is expensive, so the built map is memoized per request.
 */
final class ActionCatalogMap
{
	/** @var array<string, ActionClassification>|null Per-request memoization. */
	private ?array $map = null;

	public function __construct(
		private readonly Searcher $searcher,
		private readonly LoggerInterface $logger,
	)
	{
	}

	/** @return array<string, ActionClassification> keyed by normalized activity code. */
	public function getAll(): array
	{
		return $this->map ??= $this->buildMap(
			$this->searcher->searchByType(ActivityType::NODE_ACTION->value),
		);
	}

	/**
	 * Build the map from an already-loaded NODE_ACTION collection, saving the second folder
	 * traversal when the caller has just performed the same search. No-op when the map is
	 * already memoized. Classification is context-free, so any NODE_ACTION collection fits.
	 */
	public function warmUp(Activities $descriptions): void
	{
		$this->map ??= $this->buildMap($descriptions);
	}

	/** @return array<string, ActionClassification> keyed by normalized activity code. */
	private function buildMap(Activities $descriptions): array
	{
		$map = [];
		foreach ($descriptions as $description)
		{
			/** @var ActivityDescription $description */
			$code = $this->searcher->normalizeActivityCode((string)$description->getClass());
			$settings = $description->getNodeActionSettingsDto();
			if ($settings === null)
			{
				continue;
			}

			$classification = ActionClassification::fromNodeActionSettings($code, $settings);
			if ($classification === null)
			{
				if ($settings->group !== null || $settings->area !== null)
				{
					$this->logger->warning('Incomplete action classification for {code}', ['code' => $code]);
				}

				continue;
			}

			$filtered = $this->filterByExecutableDocuments($classification, $description->getFilter());
			if ($classification->objects !== [] && $filtered->objects === [])
			{
				// Fail-closed: a declaration whose every object is non-executable must not
				// surface as an object-less action — the activity stays in flat mode.
				$this->logger->warning(
					'Every declared object of {code} is dropped by its FILTER — classification skipped',
					['code' => $code],
				);

				continue;
			}

			$map[$code] = $filtered;
		}

		return $map;
	}

	public function getClassification(string $activityCode): ?ActionClassification
	{
		return $this->getAll()[$this->searcher->normalizeActivityCode($activityCode)] ?? null;
	}

	/**
	 * Classifications usable in this installation: an action is offered only when the module of
	 * its area is installed. Single owner of the module gate — consumers must not re-implement it.
	 *
	 * @return array<string, ActionClassification> keyed by normalized activity code.
	 */
	public function getAvailable(): array
	{
		return array_filter(
			$this->getAll(),
			fn(ActionClassification $classification) => $this->isAreaModuleInstalled($classification->area),
		);
	}

	public function getAvailableClassification(string $activityCode): ?ActionClassification
	{
		$classification = $this->getClassification($activityCode);

		return $classification !== null && $this->isAreaModuleInstalled($classification->area)
			? $classification
			: null
		;
	}

	/** @return list<ActionClassification> */
	public function getByGroup(ActionGroup $group): array
	{
		return array_values(array_filter(
			$this->getAll(),
			static fn(ActionClassification $classification) => $classification->group === $group,
		));
	}

	private function isAreaModuleInstalled(ActionArea $area): bool
	{
		return ModuleManager::isModuleInstalled($area->getModuleId());
	}

	/**
	 * Drop declared objects that the activity FILTER cannot execute (Q-AFC-5), so only
	 * executable combinations reach the catalog. Rebuilds the classification only when at
	 * least one object was dropped.
	 */
	private function filterByExecutableDocuments(
		ActionClassification $classification,
		?array $filter,
	): ActionClassification
	{
		$objects = [];
		foreach ($classification->objects as $object)
		{
			if ($this->isObjectExecutable($object, $filter))
			{
				$objects[] = $object;
			}
			else
			{
				$this->logger->warning(
					'Action object {object} is not executable by {code} per its FILTER — dropped',
					['object' => $object->id, 'code' => $classification->activityCode],
				);
			}
		}

		if (count($objects) === count($classification->objects))
		{
			return $classification;
		}

		return new ActionClassification(
			$classification->activityCode,
			$classification->group,
			$classification->area,
			$objects,
		);
	}

	/**
	 * Conservative module-prefix match: with no FILTER any object is executable; when
	 * FILTER.INCLUDE is set the object area module must appear in it; a module-level
	 * FILTER.EXCLUDE entry ([module]) removes the whole module.
	 */
	private function isObjectExecutable(ActionObject $object, ?array $filter): bool
	{
		if ($filter === null)
		{
			return true;
		}

		$moduleId = $object->area->getModuleId();

		$include = $filter['INCLUDE'] ?? null;
		if (is_array($include) && $include !== [])
		{
			$included = false;
			foreach ($include as $rule)
			{
				if (is_array($rule) && isset($rule[0]) && (string)$rule[0] === $moduleId)
				{
					$included = true;
					break;
				}
			}

			if (!$included)
			{
				return false;
			}
		}

		$exclude = $filter['EXCLUDE'] ?? null;
		if (is_array($exclude))
		{
			foreach ($exclude as $rule)
			{
				if (is_array($rule) && count($rule) === 1 && (string)($rule[0] ?? '') === $moduleId)
				{
					return false;
				}
			}
		}

		return true;
	}
}
