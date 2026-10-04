<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Bizproc\Activity\Dto\NodeSettings;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
class BlockTypeDetail
{
	public function __construct(
		public readonly BlockType $block,
		public readonly SettingCollection $settings,
		public readonly ReturnFieldCollection $returnFields,
		/** @var array<string, string> [type => description, ...] $describedTypes */
		public readonly array $describedTypes = [],
		/** @var list<string> activity properties that exist but cannot be expressed in the settings schema */
		public readonly array $skippedSettings = [],
		/** @var array<string, mixed>|null preset default property values (null = no preset applied) */
		public readonly ?array $defaultValues = null,
		/** Node topology (width/height/ports) from the same source as the node editor; null = block has no node settings */
		public readonly ?NodeSettings $nodeSettings = null,
		/** Complex-node detail (sub-action dictionary + fixed document type + filter support); null = block is not a complex node */
		public readonly ?ComplexBlockDetail $complexDetail = null,
	) {}

	public function toArray(): array
	{
		$array = [
			'block' => $this->block->toArray(),
			'settings' => $this->settings->toArray(),
		];

		if ($this->returnFields->getIterator()->count() > 0)
		{
			$array['returnFields'] = $this->returnFields->toArray();
		}

		if (!empty($this->describedTypes))
		{
			$array['typesDescription'] = $this->describedTypes;
		}

		if ($this->defaultValues !== null)
		{
			$array['defaultValues'] = $this->defaultValues;
		}

		if ($this->nodeSettings !== null)
		{
			$array['defaultSettings'] = $this->nodeSettings->toArray();
		}

		if ($this->complexDetail !== null)
		{
			$array['complexActions'] = $this->complexDetail->toArray();
		}

		return $array;
	}

	/**
	 * Port title marking a loop-back port on a loop node. The title appears on different sides depending
	 * on the node: ForEach carries it on a dedicated body-facing *input* (i1), so the loop body must close
	 * back into that input instead of the ordinary entry input; While carries it on the *output* side (o0,
	 * into the body) and has no separate loop-back input - its body closes back into the ordinary entry
	 * input i0. Source: activity NODE_SETTINGS.ports (Port title).
	 */
	public const LOOPBACK_PORT_TITLE = '->>';

	/**
	 * @return array{input: list<string>, output: list<string>} known port ids per direction (empty lists when unknown)
	 */
	public function getPortIds(): array
	{
		$ports = $this->nodeSettings?->ports;
		if ($ports === null)
		{
			return ['input' => [], 'output' => []];
		}

		return [
			'input' => self::collectPortIds($ports->input?->toArray() ?? []),
			'output' => self::collectPortIds($ports->output?->toArray() ?? []),
		];
	}

	/**
	 * Ids of the block's loop-back input ports (input ports titled with LOOPBACK_PORT_TITLE).
	 * A loop node with a loop-back *input* (ForEach:i1) returns a non-empty list; non-loop blocks return
	 * an empty list.
	 *
	 * Note the intentional asymmetry: this reports only loop-back *inputs*. While has no loop-back input
	 * (its loop-back is on the output side, o0), so it returns [] here - by design. Consequently the
	 * input-based loop-closure check in {@see AgentConnectionsValidator::validateLoopClosure()} applies to
	 * ForEach but not to While. This is a limitation of the input-based guard, not a topology defect.
	 *
	 * @return list<string>
	 */
	public function getLoopbackInputPortIds(): array
	{
		$input = $this->nodeSettings?->ports?->input?->toArray() ?? [];

		$ids = [];
		foreach ($input as $port)
		{
			$id = (string)($port['id'] ?? '');
			if ($id !== '' && (string)($port['title'] ?? '') === self::LOOPBACK_PORT_TITLE)
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param list<array{id?: string}> $ports
	 * @return list<string>
	 */
	private static function collectPortIds(array $ports): array
	{
		$ids = [];
		foreach ($ports as $port)
		{
			$id = (string)($port['id'] ?? '');
			if ($id !== '')
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}
}