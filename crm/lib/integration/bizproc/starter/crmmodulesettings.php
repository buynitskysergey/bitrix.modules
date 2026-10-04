<?php

namespace Bitrix\Crm\Integration\BizProc\Starter;

use Bitrix\Bizproc\Starter\Document;
use Bitrix\Bizproc\Starter\ModuleSettings;
use Bitrix\Bizproc\Starter\Dto\TriggerDescriptorDto;
use Bitrix\Bizproc\Starter\Dto\TriggerUpgradeDto;
use Bitrix\Crm\Automation\Factory;
use Bitrix\Crm\Automation\Trigger\BaseTrigger;
use Bitrix\Crm\Integration\BizProc\Starter\Mixins\Dto\TriggerBindingDocumentsDto;
use Bitrix\Crm\Integration\BizProc\Starter\Mixins\TriggerBindingDocumentsTrait;
use CCrmOwnerType;

if (
	!\Bitrix\Main\Loader::includeModule('bizproc')
	|| !class_exists(ModuleSettings::class)
)
{
	return;
}

final class CrmModuleSettings extends ModuleSettings
{
	use TriggerBindingDocumentsTrait;

	private const CREATE_DOCUMENT_TRIGGER = 'CrmEntityCreateTrigger';
	private const EDIT_DOCUMENT_TRIGGER = 'CrmEntityEditTrigger';
	private const FIELD_CHANGED_DOCUMENT_TRIGGER = 'CrmEntityFieldChangedTrigger';

	private int $entityTypeId;

	public function __construct(array $complexDocumentType)
	{
		parent::__construct($complexDocumentType);

		[, , $documentType] = $this->complexType;
		$this->entityTypeId = \CCrmOwnerType::ResolveID($documentType);
	}

	public function isAutomationFeatureEnabled(): bool
	{
		return Factory::isAutomationAvailable($this->entityTypeId);
	}

	public function isScriptFeatureEnabled(): bool
	{
		return Factory::isScriptAvailable($this->entityTypeId);
	}

	public function isAutomationLimited(): bool
	{
		return Factory::isAutomationLimited($this->entityTypeId);
	}

	public function isAutomationOverLimited(): bool
	{
		return Factory::isOverLimited($this->entityTypeId);
	}

	public function getCreateDocumentTrigger(): ?TriggerDescriptorDto
	{
		return $this->resolveDocumentTriggerDescriptor(self::CREATE_DOCUMENT_TRIGGER, \CBPCrmEntityCreateTrigger::class);
	}

	/**
	 * A legacy template started on update is converted into the current trigger in the any-change mode:
	 * CrmEntityEditTrigger is not created any more, it only survives in the nodes saved before.
	 * The activity has to be loaded before its constants are read, hence the guard of its own.
	 */
	public function getEditDocumentTrigger(): ?TriggerDescriptorDto
	{
		if (!\CBPRuntime::getRuntime()->includeActivityFile(mb_strtolower(self::FIELD_CHANGED_DOCUMENT_TRIGGER)))
		{
			return null;
		}

		return $this->resolveDocumentTriggerDescriptor(
			self::FIELD_CHANGED_DOCUMENT_TRIGGER,
			\CBPCrmEntityFieldChangedTrigger::class,
			[\CBPCrmEntityFieldChangedTrigger::REACTION_MODE_ID => \CBPCrmEntityFieldChangedTrigger::MODE_ANY],
		);
	}

	/**
	 * CrmEntityEditTrigger exists only for nodes saved before the reaction mode became a property of
	 * CrmEntityFieldChangedTrigger. Such a node reacts to any change of the document, so the upgraded node
	 * gets exactly that mode instead of the class default.
	 *
	 * The DTO of the map came with the same bizproc version as the map itself, and it is checked here rather
	 * than in the guard of the file: an older bizproc must cost this module only the upgrade, while the rest
	 * of the settings keeps answering the automation that asks for them.
	 *
	 * @return array<string, TriggerUpgradeDto>
	 */
	public function getTriggerUpgradeMap(): array
	{
		if (!class_exists(TriggerUpgradeDto::class))
		{
			return [];
		}

		if (!\CBPRuntime::getRuntime()->includeActivityFile(mb_strtolower(self::FIELD_CHANGED_DOCUMENT_TRIGGER)))
		{
			return [];
		}

		return [
			self::EDIT_DOCUMENT_TRIGGER => new TriggerUpgradeDto(
				triggerType: self::FIELD_CHANGED_DOCUMENT_TRIGGER,
				properties: [
					\CBPCrmEntityFieldChangedTrigger::REACTION_MODE_ID => \CBPCrmEntityFieldChangedTrigger::MODE_ANY,
				],
			),
		];
	}

	public function getDocumentStatusFieldName(): string
	{
		if (in_array(
			$this->entityTypeId,
			[CCrmOwnerType::Lead, CCrmOwnerType::Quote, CCrmOwnerType::Order],
			true
		))
		{
			return 'STATUS_ID';
		}

		return 'STAGE_ID';
	}

	/**
	 * @return array<Document>
	 */
	public function getTriggerRelatedDocuments(string $triggerCode, ?Document $document = null): array
	{
		if (!$document || !$document->complexType)
		{
			return [];
		}

		/** @var BaseTrigger $trigger */
		$trigger = \CCrmDocument::getTriggerByCode($triggerCode, $document->complexType);
		if (!$trigger)
		{
			return [];
		}

		[$entityTypeId, $entityId] = \CCrmBizProcHelper::resolveEntityId($document->complexId);

		$bindingDocuments = self::getBindingDocuments(
			new TriggerBindingDocumentsDto($entityTypeId, $entityId, $triggerCode),
		);

		$documents = [];
		foreach ($bindingDocuments as $binding)
		{
			[$bindingEntityTypeId, $bindingEntityId] = $binding;
			$complexId = \CCrmBizProcHelper::resolveDocumentId(...$binding);
			if ($complexId && $trigger::isSupported($bindingEntityTypeId))
			{
				$documents[] = new Document($complexId);
			}
		}

		return $documents;
	}

	public function onBeforeRunAutomationOnUpdate(mixed $documentId): void
	{
		parent::onBeforeRunAutomationOnUpdate($documentId);

		[, $entityId] = \CCrmBizProcHelper::resolveEntityIdByDocumentId($documentId);

		Factory::doAutocompleteActivities($this->entityTypeId, $entityId);
	}

	/**
	 * @param class-string $triggerClass CBP* trigger class exposing getPresetByComplexDocumentType().
	 * @param array<string, mixed> $additionalProperties node properties the slot presets on top of the preset ones.
	 */
	private function resolveDocumentTriggerDescriptor(
		string $triggerType,
		string $triggerClass,
		array $additionalProperties = [],
	): ?TriggerDescriptorDto
	{
		if (!\CBPRuntime::getRuntime()->includeActivityFile(mb_strtolower($triggerType)))
		{
			return null;
		}

		$preset = $triggerClass::getPresetByComplexDocumentType($this->complexType);
		if ($preset === null)
		{
			return null;
		}

		return new TriggerDescriptorDto(
			triggerType: $triggerType,
			title: is_string($preset['NAME'] ?? null) ? $preset['NAME'] : null,
			icon: $preset['NODE_ICON'],
			properties: $this->buildTriggerProperties($preset, $additionalProperties),
			presetId: $preset['ID'],
		);
	}

	private function buildTriggerProperties(array $preset, array $additionalProperties): array
	{
		$properties = is_array($preset['PROPERTIES'] ?? null) ? $preset['PROPERTIES'] : [];
		$properties['Document'] = implode('@', $this->complexType);

		return array_merge($properties, $additionalProperties);
	}
}
