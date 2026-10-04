<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class SortItem extends Dto
{
	public function __construct(
		public string $id = '',
		public string $title = '',
		public array $value = [],
	)
	{
		parent::__construct();
	}
}
