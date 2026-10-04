<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

final class AgentBlock implements Arrayable
{
	public function __construct(
		public readonly string $type,
		public readonly string $title,
		public readonly string $id,
		public readonly AgentSettingCollection $settings,
		public readonly ?string $description = null,
		public readonly ?string $presetId = null,
		/** Nested rules projection (DTO-01) for complex nodes; null for simple/operator nodes */
		public readonly ?AgentComplexRules $rules = null,
		/**
		 * Read-only projection of the block's output (return) properties, shape
		 * `[{id, name, type, multiple, default}]`. Emitted only on the reverse path (`template.get`) so the
		 * agent can build `{=<blockId>:<outputProperty>}` references - e.g. a complex sub-action `document`
		 * pointing at a source trigger's `ReturnDocument`. Empty for blocks that expose no outputs.
		 *
		 * Not consumed on the forward path (draft.add): {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentBlockValidator}
		 * never reads it, so a naive re-send of the reverse output ignores the field.
		 *
		 * @var list<array{id: string, name: string, type: string, multiple: bool, default: mixed}>
		 */
		public readonly array $returnProperties = [],
		/**
		 * Ids of the blocks logically grouped by a frame overlay (DTO-01). Same field name on both
		 * directions: on the forward path (draft.add) the REST agent sets membership; on the reverse path
		 * (template.get) the server computes it. Empty for every non-frame block.
		 *
		 * @var list<string>
		 */
		public readonly array $memberBlockIds = [],
		/** Frame background/border colour name (DTO-01); null when the block carries no frame styling. */
		public readonly ?string $frameColorName = null,
		/** Frame free-text content in BBCode (DTO-01); null when the block carries no frame styling. */
		public readonly ?string $frameContent = null,
	) {}

	public function toArray(): array
	{
		$array = [
			'type' => $this->type,
			'title' => $this->title,
			'id' => $this->id,
			'settings' => $this->settings->toArray(),
			'description' => $this->description,
			'presetId' => $this->presetId,
		];

		if ($this->rules !== null)
		{
			$array['rules'] = $this->rules->toArray();
		}

		if ($this->returnProperties !== [])
		{
			$array['returnProperties'] = $this->returnProperties;
		}

		if ($this->memberBlockIds !== [])
		{
			$array['memberBlockIds'] = $this->memberBlockIds;
		}

		if ($this->frameColorName !== null)
		{
			$array['frameColorName'] = $this->frameColorName;
		}

		if ($this->frameContent !== null)
		{
			$array['frameContent'] = $this->frameContent;
		}

		return $array;
	}
}
