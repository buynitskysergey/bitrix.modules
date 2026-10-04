<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class Category extends Dto
{
	public function __construct(
		public string $id = '',
		public string $code = '',
		public string $title = '',
		public string $description = '',
		public int $appsCount = 0,
		public string $color = '',
		public string $url = '',
	)
	{
		parent::__construct();
	}
}
