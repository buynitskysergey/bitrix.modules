<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class Promo extends Dto
{
	public function __construct(
		public int $freeDays = 0,
	)
	{
		parent::__construct();
	}
}
