<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\BizprocDesigner\Internal\Entity\Collection\BlockCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\ConnectionCollection;
use Bitrix\Main\Type\Contract\Arrayable;

final class Draft implements Arrayable
{
	public function __construct(
		public int $draftId = 0,
		public int $templateId = 0,
		public int $userId = 0,
		public BlockCollection $blocks = new BlockCollection(),
		public ConnectionCollection $connections = new ConnectionCollection(),
	)
	{
	}

	public static function createFromArray(array $data): self
	{
		$blocks = new BlockCollection();
		$connections = new ConnectionCollection();

		if (!empty($data['blocks']))
		{
			$blocks->fill($data['blocks']);
		}

		if (!empty($data['connections']))
		{
			$connections->fill($data['connections']);
		}

		return new static(
			(int)($data['draftId'] ?? 0),
			(int)($data['templateId'] ?? 0),
			(int)($data['userId'] ?? 0),
			$blocks,
			$connections,
		);
	}

	public function toArray(): array
	{
		return [
			'draftId' => $this->draftId,
			'templateId' => $this->templateId,
			'userId' => $this->userId,
			'blocks' => $this->blocks->toArray(),
			'connections' => $this->connections->toArray(),
		];
	}
}
