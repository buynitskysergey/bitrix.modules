<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class InstallFlowData extends Dto
{
	public function __construct(
		public bool $isAvailable = false,
		public ?App $app = null,
		public array $scopes = [],
		public array $agreements = [],
		public ?InstallInfo $installInfo = null,
	)
	{
		parent::__construct();
	}
}
