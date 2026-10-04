<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class InstallInfo extends Dto
{
	public function __construct(
		public string $appCode = '',
		public int $appVersion = 0,
		public string $checkHash = '',
		public string $installHash = '',
	)
	{
		parent::__construct();
	}
}
