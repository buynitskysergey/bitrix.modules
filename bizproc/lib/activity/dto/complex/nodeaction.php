<?php

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Bizproc\Activity\Enum\ActionGroup;
use Bitrix\Main\Type\Contract\Arrayable;

final class NodeAction implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly string $activityCode,
		public readonly ?string $customName = null,
		public readonly int $sort = 0,
		public readonly ?string $presetId = null,
		public readonly ?ActionGroup $group = null,
	) {}

	public function toArray(): array
	{
		return [
			'activityCode' => $this->activityCode,
			'customName' => $this->customName,
			'sort' => $this->sort,
			'presetId' => $this->presetId,
			'group' => $this->group?->value,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	public function toPreset(): array
	{
		return [
			'NAME' => $this->customName,
			'SORT' => $this->sort,
		];
	}
}
