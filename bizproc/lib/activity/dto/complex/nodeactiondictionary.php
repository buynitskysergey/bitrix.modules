<?php

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Bizproc\Activity\Enum\ActionGroup;
use Bitrix\Main\Type\Contract\Arrayable;

final class NodeActionDictionary implements Arrayable, \JsonSerializable
{
	private array $map = [];

	public function __construct(NodeAction ...$actionList)
	{
		foreach ($actionList as $action)
		{
			$this->map[$action->activityCode] = $action;
		}
	}

	/**
	 * Reconstruct NodeActionDictionary from the plain array produced by toArray().
	 * Each value may be a NodeAction instance (direct round-trip) or a plain array
	 * (after json_encode/json_decode or serialization).
	 */
	public static function fromArrayData(array $data): self
	{
		$instance = new self();
		foreach ($data as $code => $entry)
		{
			if ($entry instanceof NodeAction)
			{
				$instance->map[$entry->activityCode] = $entry;
			}
			elseif (is_array($entry))
			{
				$activityCode = $entry['activityCode'] ?? (is_string($code) ? $code : '');
				if ($activityCode === '')
				{
					continue;
				}

				$groupValue = $entry['group'] ?? null;
				$group = $groupValue !== null ? ActionGroup::tryFrom((string)$groupValue) : null;

				$instance->map[$activityCode] = new NodeAction(
					activityCode: $activityCode,
					customName: isset($entry['customName']) ? (string)$entry['customName'] : null,
					sort: isset($entry['sort']) ? (int)$entry['sort'] : 0,
					presetId: isset($entry['presetId']) ? (string)$entry['presetId'] : null,
					group: $group,
				);
			}
		}

		return $instance;
	}

	public function add(NodeAction $action): self
	{
		$this->map[$action->activityCode] = $action;

		return $this;
	}

	public function get(string $activityId): ?NodeAction
	{
		return $this->map[$activityId] ?? null;
	}

	public function isEmpty(): bool
	{
		return empty($this->map);
	}

	public function toArray(): array
	{
		return $this->map;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
