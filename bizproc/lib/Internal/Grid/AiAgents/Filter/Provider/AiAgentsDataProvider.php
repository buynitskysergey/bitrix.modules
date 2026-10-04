<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Filter\Provider;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Filter\EntityDataProvider;
use Bitrix\Main\Filter\Field;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateSection;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Filter\AiAgentsFilterSettings;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Visibility\HiddenAiAgentsRegistry;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;

class AiAgentsDataProvider extends EntityDataProvider
{
	private AiAgentsFilterSettings $settings;
	private HiddenAiAgentsRegistry $hiddenAiAgents;

	public function __construct(AiAgentsFilterSettings $settings, ?HiddenAiAgentsRegistry $hiddenAiAgents = null)
	{
		$this->settings = $settings;
		$this->hiddenAiAgents = $hiddenAiAgents ?? ServiceLocator::getInstance()->get(HiddenAiAgentsRegistry::class);
	}

	public function getSettings(): AiAgentsFilterSettings
	{
		return $this->settings;
	}

	/**
	 * @param string $fieldID Field ID.
	 */
	public function prepareFieldData($fieldID): ?array
	{
		if ($fieldID === AiAgentsFilterSettings::LAUNCHED_BY_FIELD)
		{
			return [
				'params' => [
					'multiple' => 'Y',
					'dialogOptions' => [
						'context' => 'filter',
						'entities' => [
							[
								'id' => 'user',
								'dynamicLoad' => true,
								'dynamicSearch' => true,
							],
						],
					],
				],
			];
		}

		if ($fieldID === AiAgentsFilterSettings::AGENT_TEMPLATE_FIELD)
		{
			return [
				'params' => [
					'multiple' => 'Y',
				],
				'items' => $this->getAgentTemplateItems(),
			];
		}

		return null;
	}

	/**
	 * @param string $fieldID Field ID.
	 */
	protected function getFieldName($fieldID): string
	{
		if (!is_string($fieldID))
		{
			return '';
		}

		return match ($fieldID)
		{
			AiAgentsFilterSettings::LAUNCHED_BY_FIELD =>
				Loc::getMessage("BIZPROC_AI_AGENTS_COLUMN_LAUNCHED_BY") ?? '',
			AiAgentsFilterSettings::AGENT_TEMPLATE_FIELD =>
				Loc::getMessage('BIZPROC_AI_AGENTS_FILTER_AGENT_TEMPLATE') ?? '',
			AiAgentsFilterSettings::IS_ACTIVE_FIELD =>
				Loc::getMessage('BIZPROC_AI_AGENTS_FILTER_IS_ACTIVE') ?? '',
			default => $fieldID,
		};
	}

	/**
	 * Builds filter options for the AGENT_TEMPLATE field: available system AI-agent
	 * templates keyed by SYSTEM_CODE. Hidden system codes are excluded with the same
	 * criterion the grid applies, so filter options match visible rows.
	 *
	 * @return array<string, string>
	 */
	private function getAgentTemplateItems(): array
	{
		$hiddenSystemCodes = $this->hiddenAiAgents->getHiddenSystemCodes();

		$query = WorkflowTemplateTable::query()
			->setSelect(['SYSTEM_CODE', 'NAME'])
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNotNull('SYSTEM_CODE')
			->registerRuntimeField(
				'SECTION',
				new Reference(
					'SECTION',
					WorkflowTemplateSectionTable::class,
					Join::on('this.ID', 'ref.TEMPLATE_ID'),
				),
			)
			->where('SECTION.SECTION_ID', WorkflowTemplateSection::AiAgent->value)
		;

		if ($hiddenSystemCodes)
		{
			$query->whereNotIn('SYSTEM_CODE', $hiddenSystemCodes);
		}

		$items = [];
		foreach ($query->fetchAll() as $row)
		{
			$items[(string)$row['SYSTEM_CODE']] = (string)$row['NAME'];
		}

		return $items;
	}

	/**
	 * @return array<string, Field>
	 */
	public function prepareFields(): array
	{
		$result = [];

		$result[AiAgentsFilterSettings::LAUNCHED_BY_FIELD] = $this->createField(
			AiAgentsFilterSettings::LAUNCHED_BY_FIELD,
			[
				'type' => 'entity_selector',
				'default' => true,
				'partial' => true,
			],
		);

		$result[AiAgentsFilterSettings::AGENT_TEMPLATE_FIELD] = $this->createField(
			AiAgentsFilterSettings::AGENT_TEMPLATE_FIELD,
			[
				'type' => 'list',
				'default' => true,
				'partial' => true,
			],
		);

		// Filter-only field: activity is a state of the launched copy, not a grid column.
		$result[AiAgentsFilterSettings::IS_ACTIVE_FIELD] = $this->createField(
			AiAgentsFilterSettings::IS_ACTIVE_FIELD,
			[
				'type' => 'checkbox',
				'default' => false,
			],
		);

		return $result;
	}
}
