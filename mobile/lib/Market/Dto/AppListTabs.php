<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;
use Bitrix\Mobile\Market\TabsMode;

final class AppListTabs extends Dto
{
	public function __construct(
		public TabsMode $mode = TabsMode::Navigate,
		public string $initialTabId = '',
		public array $items = [],
	)
	{
		parent::__construct();
	}

	public function toArray(): array
	{
		$result = [
			'mode' => $this->mode->value,
			'items' => [],
		];

		if ($this->initialTabId !== '')
		{
			$result['initialTabId'] = $this->initialTabId;
		}

		foreach ($this->items as $item)
		{
			$result['items'][] = $item instanceof Dto ? $item->toArray() : $item;
		}

		return $result;
	}
}
