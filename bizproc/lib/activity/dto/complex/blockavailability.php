<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Bizproc\Activity\Enum\NodeBlockType;
use Bitrix\Main\Type\Contract\Arrayable;

final class BlockAvailability implements Arrayable, \JsonSerializable
{
	/** @var array<string, AvailableBlock> */
	private array $map = [];

	public static function fromMap(array $map): self
	{
		$instance = new self();
		foreach ($map as $type => $block)
		{
			if ($type instanceof NodeBlockType)
			{
				$instance->map[$type->value] = $block;
			}
			elseif (is_string($type))
			{
				$instance->map[$type] = $block;
			}
		}

		return $instance;
	}

	/**
	 * Reconstruct BlockAvailability from a plain array produced by toArray().
	 * Each value may be an AvailableBlock instance (direct round-trip) or a plain array
	 * (after json_encode/json_decode or serialization).
	 */
	public static function fromArrayData(array $data): self
	{
		$instance = new self();
		foreach ($data as $type => $block)
		{
			if (!is_string($type))
			{
				continue;
			}

			if ($block instanceof AvailableBlock)
			{
				$instance->map[$type] = $block;
			}
			elseif (is_array($block))
			{
				$instance->map[$type] = AvailableBlock::fromArray($block);
			}
		}

		return $instance;
	}

	/**
	 * The set bizproc offers a node that declares no availableBlocks: condition+action+output always
	 * available, filter and relations by the runtime rules of their surfaces, the rest unavailable.
	 *
	 * A declaration replaces this default as a whole, so a node that only needs to shift one surface
	 * declares the default with that argument instead of repeating the full map: a new block type then
	 * reaches the declaring nodes together with the nodes that rely on the default.
	 */
	public static function legacyDefault(bool $filter = false, bool $relations = false): self
	{
		return self::fromMap([
			NodeBlockType::BASE_SETTINGS->value => new AvailableBlock(available: false),
			NodeBlockType::CONDITION->value => new AvailableBlock(available: true),
			NodeBlockType::ACTION->value => new AvailableBlock(available: true),
			NodeBlockType::FILTER->value => new AvailableBlock(available: $filter),
			NodeBlockType::OUTPUT->value => new AvailableBlock(available: true),
			NodeBlockType::GROUP->value => new AvailableBlock(available: false),
			NodeBlockType::RELATIONS->value => new AvailableBlock(available: $relations),
			NodeBlockType::STORAGES->value => new AvailableBlock(available: false),
		]);
	}

	public function set(NodeBlockType $type, AvailableBlock $block): self
	{
		$this->map[$type->value] = $block;

		return $this;
	}

	public function get(NodeBlockType $type): ?AvailableBlock
	{
		return $this->map[$type->value] ?? null;
	}

	public function isAvailable(NodeBlockType $type): bool
	{
		return ($this->map[$type->value] ?? null)?->available ?? false;
	}

	public function toArray(): array
	{
		$result = [];
		foreach ($this->map as $key => $block)
		{
			$result[$key] = $block->toArray();
		}

		return $result;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
