<?php

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Main\Type\Contract\Arrayable;

final class Settings implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly NodeActionDictionary $actionDictionary,
		public readonly ?BlockAvailability $availableBlocks = null,
		/** Offer every classified node action, not only the explicit dictionary entries. */
		public readonly bool $useGlobalActionCatalog = false,
		public readonly ?NodeAction $relationAction = null,
		/**
		 * The condition of the node is kept in a property of the node itself instead of the child branching
		 * activity a complex node builds. Stated here and never derived from the block composition, so the
		 * address a condition is saved at cannot move when a node starts declaring another block.
		 */
		public readonly bool $conditionInNodeProperty = false,
	) {}

	/**
	 * Reconstruct Settings from a plain array produced by toArray().
	 * Each sub-value may be an object (direct round-trip) or a plain array
	 * (after json_encode/json_decode or serialization).
	 */
	public static function fromArray(array $data): self
	{
		$actionDictionaryData = $data['actionDictionary'] ?? [];
		if ($actionDictionaryData instanceof NodeActionDictionary)
		{
			$actionDictionary = $actionDictionaryData;
		}
		elseif (is_array($actionDictionaryData))
		{
			$actionDictionary = NodeActionDictionary::fromArrayData($actionDictionaryData);
		}
		else
		{
			$actionDictionary = new NodeActionDictionary();
		}

		$availableBlocksData = $data['availableBlocks'] ?? null;
		if ($availableBlocksData === null)
		{
			$availableBlocks = null;
		}
		elseif ($availableBlocksData instanceof BlockAvailability)
		{
			$availableBlocks = $availableBlocksData;
		}
		elseif (is_array($availableBlocksData))
		{
			$availableBlocks = BlockAvailability::fromArrayData($availableBlocksData);
		}
		else
		{
			$availableBlocks = null;
		}

		$relationActionData = $data['relationAction'] ?? null;
		if ($relationActionData instanceof NodeAction)
		{
			$relationAction = $relationActionData;
		}
		elseif (is_array($relationActionData) && isset($relationActionData['activityCode']))
		{
			$relationAction = NodeActionDictionary::fromArrayData([$relationActionData])
				->get((string)$relationActionData['activityCode'])
			;
		}
		else
		{
			$relationAction = null;
		}

		return new self(
			actionDictionary: $actionDictionary,
			availableBlocks: $availableBlocks,
			useGlobalActionCatalog: (bool)($data['useGlobalActionCatalog'] ?? false),
			relationAction: $relationAction,
			conditionInNodeProperty: (bool)($data['conditionInNodeProperty'] ?? false),
		);
	}

	public function toArray(): array
	{
		$result = [
			'actionDictionary' => $this->actionDictionary->toArray(),
			'useGlobalActionCatalog' => $this->useGlobalActionCatalog,
			'relationAction' => $this->relationAction?->toArray(),
			'conditionInNodeProperty' => $this->conditionInNodeProperty,
		];

		if ($this->availableBlocks !== null)
		{
			$result['availableBlocks'] = $this->availableBlocks->toArray();
		}

		return $result;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
