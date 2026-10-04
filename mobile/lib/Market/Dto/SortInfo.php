<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class SortInfo extends Dto
{
	public function __construct(
		public ?SortItem $current = null,
		public array $items = [],
	)
	{
		parent::__construct();
	}
}
