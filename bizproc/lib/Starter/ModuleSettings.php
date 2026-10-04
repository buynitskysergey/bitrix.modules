<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Starter\Dto\TriggerDescriptorDto;
use Bitrix\Bizproc\Starter\Dto\TriggerUpgradeDto;

abstract class ModuleSettings
{
	protected readonly array $complexType;

	public function __construct(array $complexDocumentType)
	{
		$this->complexType = $complexDocumentType;
	}

	abstract public function isAutomationFeatureEnabled(): bool;

	abstract public function isScriptFeatureEnabled(): bool;

	abstract public function isAutomationLimited(): bool;

	abstract public function isAutomationOverLimited(): bool;

	/**
	 * @return TriggerDescriptorDto|null
	 */
	public function getCreateDocumentTrigger(): ?TriggerDescriptorDto
	{
		return null;
	}

	/**
	 * @return TriggerDescriptorDto|null
	 */
	public function getEditDocumentTrigger(): ?TriggerDescriptorDto
	{
		return null;
	}

	/**
	 * One-way upgrade of node-workflow trigger classes the module has retired: a node saved with a
	 * deprecated trigger type is rebuilt and stored as the actual one on the next settings save, and the
	 * listed properties are applied so that the upgraded node keeps behaving as before. There is no
	 * reverse direction: the deprecated type is never a target.
	 *
	 * @return array<string, TriggerUpgradeDto> Deprecated trigger type
	 *  (WorkflowTemplateTriggerTable.TRIGGER_TYPE) to the upgrade it resolves to.
	 */
	public function getTriggerUpgradeMap(): array
	{
		return [];
	}

	public function getDocumentStatusFieldName(): ?string
	{
		return null;
	}

	/**
	 * @return array<Document>
	 */
	public function getTriggerRelatedDocuments(string $triggerCode, ?Document $document = null): array
	{
		return $document ? [$document] : [];
	}

	public function onBeforeRunAutomationOnUpdate(mixed $documentId): void
	{}
}
