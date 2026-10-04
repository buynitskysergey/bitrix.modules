<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class HomeData extends Dto
{
	public function __construct(
		public bool $isAvailable = false,
		public string $title = '',
		public array $categories = [],
		public ?Promo $promo = null,
		public bool $canViewInstalledList = false,
	)
	{
		parent::__construct();
	}
}
