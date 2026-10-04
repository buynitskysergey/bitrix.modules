<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

final class AgentConnection implements Arrayable
{
	public function __construct(
		public readonly string $destinationBlockId,
		public readonly string $sourceBlockId,
		public readonly ?string $sourcePortId = null,
		public readonly ?string $targetPortId = null,
	) {}

	public function toArray(): array
	{
		$array = [
			'destinationBlockId' => $this->destinationBlockId,
			'sourceBlockId' => $this->sourceBlockId,
		];

		if ($this->sourcePortId !== null)
		{
			$array['sourcePortId'] = $this->sourcePortId;
		}

		if ($this->targetPortId !== null)
		{
			$array['targetPortId'] = $this->targetPortId;
		}

		return $array;
	}
}