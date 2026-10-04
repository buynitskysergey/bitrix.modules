<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * The area the pilot visibility rule acts on - the templates published to a pilot and having no common
 * version. The whole area is held in the cache rather than counted: it is small by construction, and the
 * rule asks it on paths where a query per selection is a price the product does not pay (the preparation
 * of a start form among them).
 *
 * The area is defined by two settings of a template, and both outlive the snapshot of the pilot: the mark
 * stays when the pilot is stopped, and that is what keeps a template with no common version hidden from
 * everyone afterwards. A template leaves the area only when a common version appears, which is
 * why "is there anything to hide" cannot be answered by whether the portal runs any pilot
 * ({@see PilotPresence}) - that one turns to no at the stop, and the rule would stop being asked exactly
 * where it matters most.
 *
 * A template that has never been published to a pilot is out of the area, so on the portals the feature is
 * not used on the rule costs one cache read and nothing else.
 *
 * The area is expected to hold a handful of templates. Should a portal ever grow it past {@see
 * self::AREA_LIMIT}, the list is not kept at all: the state says "too many to hold", and every question is
 * answered by a query narrowed to what was asked about. The rule stays exactly as correct either way.
 */
final class RestrictedTemplateArea extends PilotPortalCache
{
	private const CACHE_ID = 'bizproc_pilot_restricted_area';
	private const PRESENCE_OPTION = 'pilot_restricted_template_presence';

	/**
	 * The size past which the area is queried instead of held. Reached only by a portal that published
	 * hundreds of templates to a pilot without ever publishing them for everyone.
	 */
	private const AREA_LIMIT = 1000;

	private const COMMON_REVISION_RELATION = 'COMMON_REVISION';

	public function hasAnyRestrictedTemplate(): bool
	{
		$state = $this->value();

		return ($state['overflow'] ?? false) || ($state['ids'] ?? []) !== [];
	}

	/**
	 * The templates of the area, narrowed to the ones asked about.
	 *
	 * @param int[]|null $templateIds the templates asked about; null asks about the whole portal
	 * @return int[]
	 */
	public function getRestrictedTemplateIds(?array $templateIds = null): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		$state = $this->value();

		if ($state['overflow'] ?? false)
		{
			return $this->queryRestrictedIds($templateIds);
		}

		$area = $state['ids'] ?? [];
		if ($templateIds === null || $area === [])
		{
			return $area;
		}

		return array_values(array_intersect($area, $this->normalizeTemplateIds($templateIds)));
	}

	public function isRestricted(int $templateId): bool
	{
		return $templateId > 0 && $this->getRestrictedTemplateIds([$templateId]) !== [];
	}

	protected function cacheId(): string
	{
		return self::CACHE_ID;
	}

	protected function presenceOptionName(): string
	{
		return self::PRESENCE_OPTION;
	}

	protected function emptyValue(): array
	{
		return ['ids' => [], 'overflow' => false];
	}

	protected function hasStoredValue(array $value): bool
	{
		return ($value['overflow'] ?? false) || ($value['ids'] ?? []) !== [];
	}

	/**
	 * One element over the limit is asked for on purpose: it is what tells a full area from an overflowing
	 * one without counting the whole table.
	 */
	protected function readFromStorage(): array
	{
		$ids = $this->queryRestrictedIds(null, self::AREA_LIMIT + 1);

		return count($ids) > self::AREA_LIMIT
			? ['ids' => [], 'overflow' => true]
			: ['ids' => $ids, 'overflow' => false]
		;
	}

	/**
	 * The rule itself: the pilot mark is set and the revision of the common scheme is empty. The set is
	 * built without the audience service - the branch that hides everything from everyone needs it too.
	 *
	 * A stored revision of an empty string and no stored revision at all mean the same thing here: the
	 * template has no common version.
	 *
	 * @param int[]|null $templateIds
	 * @return int[]
	 */
	private function queryRestrictedIds(?array $templateIds, ?int $limit = null): array
	{
		$query = WorkflowTemplateSettingsTable::query()
			->registerRuntimeField(
				self::COMMON_REVISION_RELATION,
				new Reference(
					self::COMMON_REVISION_RELATION,
					WorkflowTemplateSettingsTable::class,
					Join::on('this.TEMPLATE_ID', 'ref.TEMPLATE_ID')
						->where('ref.NAME', PilotTemplateSettings::COMMON_SCHEME_REVISION),
					['join_type' => Join::TYPE_LEFT],
				),
			)
			->setSelect(['TEMPLATE_ID'])
			->where('NAME', PilotTemplateSettings::PILOT_PUBLISHED)
			->where('VALUE', PilotTemplateSettings::PILOT_PUBLISHED_VALUE)
			->where(
				Query::filter()
					->logic('or')
					->whereNull(self::COMMON_REVISION_RELATION. '.VALUE')
					->where(self::COMMON_REVISION_RELATION. '.VALUE', '')
			)
			->setDistinct()
		;

		if ($templateIds !== null)
		{
			$query->whereIn('TEMPLATE_ID', $this->normalizeTemplateIds($templateIds));
		}

		if ($limit !== null)
		{
			$query->setLimit($limit);
		}

		return array_map('intval', array_column($query->fetchAll(), 'TEMPLATE_ID'));
	}

	/**
	 * @param int[] $templateIds
	 * @return int[]
	 */
	private function normalizeTemplateIds(array $templateIds): array
	{
		$normalized = [];
		foreach ($templateIds as $templateId)
		{
			$templateId = (int)$templateId;
			if ($templateId > 0)
			{
				$normalized[$templateId] = $templateId;
			}
		}

		return array_values($normalized);
	}
}
