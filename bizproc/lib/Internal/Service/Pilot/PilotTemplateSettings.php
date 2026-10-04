<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Main\Repository\Exception\PersistenceException;

/**
 * Typed access to the two template settings the pilot visibility rule stands on. Both outlive the
 * pilot snapshot on purpose: the mark keeps a template hidden after the pilot is stopped, and the
 * common scheme revision tells whether the template has a common version at all - without reading
 * the scheme itself.
 */
final class PilotTemplateSettings
{
	public const PILOT_PUBLISHED = 'PILOT_PUBLISHED';
	public const COMMON_SCHEME_REVISION = 'COMMON_SCHEME_REVISION';

	/** The stored value of the mark: the area of the visibility rule is asked by a query of its own. */
	public const PILOT_PUBLISHED_VALUE = 'Y';

	/**
	 * @throws PersistenceException
	 */
	public function markPilotPublished(int $templateId): void
	{
		$this->saveSetting($templateId, self::PILOT_PUBLISHED, self::PILOT_PUBLISHED_VALUE);
	}

	public function isPilotPublished(int $templateId): bool
	{
		return $this->getSetting($templateId, self::PILOT_PUBLISHED) === self::PILOT_PUBLISHED_VALUE;
	}

	/**
	 * @throws PersistenceException
	 */
	public function setCommonSchemeRevision(int $templateId, string $revision): void
	{
		$this->saveSetting($templateId, self::COMMON_SCHEME_REVISION, $revision);
	}

	/**
	 * The stored fingerprint, and null while nothing was ever stored - the two are not the same answer, and
	 * whether the template has a common version is decided by {@see CommonRevisionWriter} alone: a template
	 * written before the feature existed keeps its fingerprint unstored until the first demand.
	 */
	public function getCommonSchemeRevision(int $templateId): ?string
	{
		return $this->getSetting($templateId, self::COMMON_SCHEME_REVISION);
	}

	/**
	 * Both settings of a whole template list in one query: reading them template by template would
	 * cost a query per row of the grid.
	 *
	 * @param int[] $templateIds
	 * @return array<int, array<string, string>> values by template, keys are the constants above
	 */
	public function getByTemplateIds(array $templateIds): array
	{
		$templateIds = array_values(array_filter(array_unique(array_map('intval', $templateIds))));
		if (!$templateIds)
		{
			return [];
		}

		$rows = WorkflowTemplateSettingsTable::query()
			->setSelect(['TEMPLATE_ID', 'NAME', 'VALUE'])
			->whereIn('TEMPLATE_ID', $templateIds)
			->whereIn('NAME', [self::PILOT_PUBLISHED, self::COMMON_SCHEME_REVISION])
			->exec()
		;

		$settings = [];
		while ($row = $rows->fetch())
		{
			$settings[(int)$row['TEMPLATE_ID']][(string)$row['NAME']] = (string)($row['VALUE'] ?? '');
		}

		return $settings;
	}

	private function getSetting(int $templateId, string $name): ?string
	{
		$row = WorkflowTemplateSettingsTable::query()
			->setSelect(['VALUE'])
			->where('TEMPLATE_ID', $templateId)
			->where('NAME', $name)
			->setLimit(1)
			->fetch()
		;

		return $row ? (string)($row['VALUE'] ?? '') : null;
	}

	/**
	 * @throws PersistenceException
	 */
	private function saveSetting(int $templateId, string $name, string $value): void
	{
		$row = WorkflowTemplateSettingsTable::query()
			->setSelect(['ID'])
			->where('TEMPLATE_ID', $templateId)
			->where('NAME', $name)
			->setLimit(1)
			->fetch()
		;

		$result = $row
			? WorkflowTemplateSettingsTable::update((int)$row['ID'], ['VALUE' => $value])
			: WorkflowTemplateSettingsTable::add([
				'TEMPLATE_ID' => $templateId,
				'NAME' => $name,
				'VALUE' => $value,
			])
		;

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				"Unable to save the template setting {$name}: ". implode('; ', $result->getErrorMessages())
			);
		}
	}
}
