<?php

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateSection;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\Query\LaunchedCopyQuery;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Bizproc\WorkflowTemplateTable;

class AiAgentRepository
{
	/**
	 * @param list<int> $ids
	 *
	 * @return list<int>
	 */
	public function getOnlyExistAndAllowedToDeleteTemplateIds(
		array $ids,
		bool $isUserAdmin,
		int $userIdDeleteBy,
		bool $ignoreOwner = false,
	): array
	{
		$ids = array_filter(array_map(static fn($id) => (int)$id, $ids));
		if (empty($ids))
		{
			return [];
		}

		$query = WorkflowTemplateTable::query()
			->whereIn('ID', $ids)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setSelect(['ID'])
		;

		if (!$isUserAdmin && !$ignoreOwner)
		{
			$query
				->where('ACTIVATED_BY', $userIdDeleteBy)
			;
		}

		$rows = $query->fetchAll();

		return array_map(
			static fn($id) => (int)$id,
			array_column($rows, 'ID'),
		);
	}

	/**
	 * Single source of truth ownership predicate for launched AI-agent templates.
	 * Mirrors the grid/delete criterion: launched copy (TYPE=Nodes, SYSTEM_CODE IS NULL),
	 * current user is the owner (ACTIVATED_BY) or an admin, optionally already started.
	 */
	public function canManageLaunchedTemplate(
		int $templateId,
		int $userId,
		bool $isUserAdmin,
		bool $requireStarted = false,
	): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		$query = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setSelect(['ID'])
			->setLimit(1)
		;

		if (!$isUserAdmin)
		{
			$query->where('ACTIVATED_BY', $userId);
		}

		if ($requireStarted)
		{
			$query->whereNotNull('ACTIVATED_AT');
		}

		return !empty($query->fetch());
	}

	/**
	 * Owner (ACTIVATED_BY) of a launched AI-agent copy (TYPE=Nodes, SYSTEM_CODE IS NULL);
	 * null when $templateId is not such a copy or carries no owner. Used to re-launch a
	 * freshly upgraded copy from its owner instead of the administrator who triggered the
	 * upgrade, so the onboarding start branch is attributed to the owner.
	 */
	public function getActivatedBy(int $templateId): ?int
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$value = WorkflowTemplateTable::query()
			->setSelect(['ACTIVATED_BY'])
			->where('ID', $templateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setLimit(1)
			->fetch()['ACTIVATED_BY'] ?? null
		;

		$value = (int)$value;

		return $value > 0 ? $value : null;
	}

	/**
	 * AI-agent template that may be used as a copyAndStart source: a Nodes template
	 * from the AI_AGENT section that is not a launched copy (ACTIVATED_AT IS NULL).
	 * Covers both a system AI-agent template (SYSTEM_CODE set) and a user-created one
	 * (SYSTEM_CODE IS NULL) added via the designer, and rejects launched copies, so the
	 * settings of an agent already activated by another user cannot be cloned by id.
	 *
	 * @return array{SYSTEM_CODE: ?string}|null null when $templateId is not a valid copy
	 *  source: a launched copy, a template of another type/section or a missing template.
	 */
	public function findCopyableAiAgentTemplate(int $templateId): ?array
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateSectionTable::query()
			->where('TEMPLATE_ID', $templateId)
			->where('SECTION_ID', WorkflowTemplateSection::AiAgent->value)
			->where('TEMPLATE.TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('TEMPLATE.ACTIVATED_AT')
			->setSelect(['ID', 'SYSTEM_CODE' => 'TEMPLATE.SYSTEM_CODE'])
			->setLimit(1)
			->fetch()
		;

		if (empty($row))
		{
			return null;
		}

		return ['SYSTEM_CODE' => $row['SYSTEM_CODE'] ?? null];
	}

	/**
	 * Whether the system template $systemCode already has at least one active launched copy in the
	 * AI_AGENT section. Existence only: the caller needs a yes/no answer, never a count, an owner
	 * or a name.
	 *
	 * The link and the definition of an active copy both come from LaunchedCopyQuery, so this
	 * predicate cannot drift away from the grid filter that the user opens right after.
	 *
	 * Who launched the copy is deliberately not part of the predicate: any active copy visible in
	 * the grid counts, including one created by a trusted scenario. Hidden system codes are not
	 * excluded here either - hiding works by SYSTEM_CODE, and a launched copy always has none, so
	 * it cannot change the set of copies; it is a property of the source template instead
	 * (LaunchableTemplateResolver).
	 */
	public function hasActiveLaunchedCopy(string $systemCode): bool
	{
		if ($systemCode === '')
		{
			return false;
		}

		$query = WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(
				'SECTION',
				new Reference(
					'SECTION',
					WorkflowTemplateSectionTable::class,
					Join::on('this.ID', 'ref.TEMPLATE_ID'),
				),
			)
			->where('SECTION.SECTION_ID', WorkflowTemplateSection::AiAgent->value)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->setLimit(1)
		;

		$query->where(LaunchedCopyQuery::joinOriginLink($query, [$systemCode]));
		LaunchedCopyQuery::applyActive($query);

		return !empty($query->fetch());
	}

	/**
	 * SYSTEM_CODE of a system AI-agent template (TYPE=Nodes, SYSTEM_CODE set),
	 * or null when it is not a system AI-agent template: a launched copy
	 * (SYSTEM_CODE IS NULL), a system template of another type (e.g.
	 * bitrix_bizproc_automation with TYPE robots) or a missing template.
	 * Mirror of canManageLaunchedTemplate on the SYSTEM_CODE side.
	 */
	public function getSystemAiAgentCode(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		return WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNotNull('SYSTEM_CODE')
			->setSelect(['SYSTEM_CODE'])
			->setLimit(1)
			->fetch()['SYSTEM_CODE'] ?? null
		;
	}

	public function saveOriginSystemCode(int $templateId, string $systemCode): void
	{
		WorkflowTemplateSettingsTable::add([
			'TEMPLATE_ID' => $templateId,
			'NAME' => WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE,
			'VALUE' => $systemCode,
		]);
	}

	public function getOriginSystemCode(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$value = WorkflowTemplateSettingsTable::query()
			->setSelect(['VALUE'])
			->where('TEMPLATE_ID', $templateId)
			->where('NAME', WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE)
			->setLimit(1)
			->fetch()['VALUE'] ?? null
		;

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Installed reference version of a launched copy: the revision of the
	 * reference logic at the moment the copy was created or last upgraded.
	 * Stored alongside ORIGIN_SYSTEM_CODE as a single-valued setting.
	 */
	public function saveOriginSystemVersion(int $templateId, string $version): void
	{
		WorkflowTemplateSettingsTable::deleteSettingsByFilter([
			'=TEMPLATE_ID' => $templateId,
			'=NAME' => WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION,
		]);

		WorkflowTemplateSettingsTable::add([
			'TEMPLATE_ID' => $templateId,
			'NAME' => WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION,
			'VALUE' => $version,
		]);
	}

	public function getOriginSystemVersion(int $templateId): ?string
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$value = WorkflowTemplateSettingsTable::query()
			->setSelect(['VALUE'])
			->where('TEMPLATE_ID', $templateId)
			->where('NAME', WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION)
			->setLimit(1)
			->fetch()['VALUE'] ?? null
		;

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Batch-reads ORIGIN_SYSTEM_CODE and ORIGIN_SYSTEM_VERSION for many templates in a single
	 * query, so the grid hot path does not issue the per-row lookups that getOriginSystemCode()
	 * and getOriginSystemVersion() would otherwise perform for every launched copy (NFR P95).
	 * Empty values are normalized to null, mirroring the single-row getters.
	 *
	 * @param list<int> $templateIds
	 * @return array<int, array{code: ?string, version: ?string}> Keyed by TEMPLATE_ID; only
	 *   templates that carry at least one of the two settings are present.
	 */
	public function getOriginSettings(array $templateIds): array
	{
		$templateIds = array_values(array_unique(array_filter(
			array_map(static fn($id) => (int)$id, $templateIds),
		)));
		if (empty($templateIds))
		{
			return [];
		}

		$rows = WorkflowTemplateSettingsTable::query()
			->setSelect(['TEMPLATE_ID', 'NAME', 'VALUE'])
			->whereIn('TEMPLATE_ID', $templateIds)
			->whereIn('NAME', [
				WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE,
				WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION,
			])
			->fetchAll()
		;

		$settings = [];
		foreach ($rows as $row)
		{
			$templateId = (int)$row['TEMPLATE_ID'];
			$value = is_string($row['VALUE']) && $row['VALUE'] !== '' ? $row['VALUE'] : null;

			if ($row['NAME'] === WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE)
			{
				$settings[$templateId]['code'] = $value;
			}
			elseif ($row['NAME'] === WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_VERSION)
			{
				$settings[$templateId]['version'] = $value;
			}
		}

		return $settings;
	}

	public function updateActivationTimestamp(int $templateId, DateTime $dateTime): UpdateResult
	{
		$fieldsToUpdate = [
			'ACTIVATED_AT' => $dateTime,
		];

		return WorkflowTemplateTable::update($templateId, $fieldsToUpdate);
	}
}
