<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Draft;

final class DraftPage
{
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly int $page,
		public readonly int $pageSize,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'items' => $this->items,
			'total' => $this->total,
			'page' => $this->page,
			'pageSize' => $this->pageSize,
		];
	}
}
