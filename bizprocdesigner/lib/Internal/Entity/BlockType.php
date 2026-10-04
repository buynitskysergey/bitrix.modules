<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class BlockType implements Arrayable
{
	public function __construct(
		public readonly string $type,
		public readonly string $description,
		public readonly ?string $presetId = null,
	) {}

	public function toArray(): array
	{
		$array = [
			'type' => $this->type,
			'description' => $this->description,
		];

		if ($this->presetId !== null)
		{
			$array['presetId'] = $this->presetId;
		}

		return $array;
	}
}