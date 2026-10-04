<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use JsonSerializable;

class LoadSettingsResponseDto implements JsonSerializable
{
	/**
	 *
	 * @param string $title
	 * @param string $description
	 * @param array<string, PortRuleDto> $portRuleDtoDictionary
	 * @param array<string, ActionDictionaryEntryDto> $actionEntryDtoDictionary
	 * @param array|null $fixedDocumentType Document type the node works on, as `[moduleId, entity, documentType]`;
	 *   null when the node has none and the document type of the edited template answers instead. A complex node
	 *   declares it on its class; a trigger publishes the document type of the event it reacts to (a trigger of
	 *   an event without a document publishes null). It is what the condition of the node compares fields of and
	 *   what the forms of its sub-actions are built for. Not a filter gate: `availableBlocks.filter.available`
	 *   is the single answer about the filter surface, and for a trigger the two are deliberately unrelated -
	 *   see {@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService::getPublishedDocumentTypeForNode()}.
	 * @param bool $filterSupported Kept for backward compatibility. Mirrors availableBlocks.filter.available;
	 *   only a node the capability catalog cannot describe at all (availableBlocks = null) falls back to the
	 *   runtime gate. The two can therefore never disagree.
	 * @param AvailableBlocksDto|null $availableBlocks Descriptor of available blocks.
	 * @param array<string, string> $autofillMap Relation autofill package for the "Create" sub-action:
	 *   target form field code => bizproc expression "{=<sourceBlockId>:<sourcePropertyId>}". Empty when
	 *   there are no relation sources or no unambiguous match. Carries the expression only; the resolver
	 *   descriptor's `required` flag is not transported and is reserved for future server-side pre-validation.
	 * @param ActionDictionaryEntryDto|null $relationAction
	 * @param array<string, PortRuleDto> $relationPortRuleDtoDictionary
	 */
	public function __construct(
		public string $title,
		public string $description,
		public array $portRuleDtoDictionary,
		public array $actionEntryDtoDictionary,
		public ?array $fixedDocumentType = null,
		public bool $filterSupported = false,
		public ?AvailableBlocksDto $availableBlocks = null,
		public array $autofillMap = [],
		public ?ActionDictionaryEntryDto $relationAction = null,
		public array $relationPortRuleDtoDictionary = [],
	)
	{

	}

	public function jsonSerialize(): array
	{
		return [
			'title' => $this->title,
			'description' => $this->description,
			'rules' => $this->portRuleDtoDictionary,
			'actions' => $this->actionEntryDtoDictionary,
			'fixedDocumentType' => $this->fixedDocumentType,
			'filterSupported' => $this->filterSupported,
			'availableBlocks' => $this->availableBlocks,
			'autofillMap' => $this->autofillMap,
			'relationAction' => $this->relationAction,
			'relations' => $this->relationPortRuleDtoDictionary,
		];
	}
}
