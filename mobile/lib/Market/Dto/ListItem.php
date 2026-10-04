<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class ListItem extends Dto
{
	public function __construct(
		public string $id = '',
		public string $code = '',
		public string $title = '',
		public string $description = '',
		public string $imageUrl = '',
		public int $installsCount = 0,
		public string $detailUrl = '',
		public string $openAppUrl = '',
		public string $actionTitle = '',
		public string $actionType = '',
		public bool $isInstallAction = false,
		public bool $showActionButton = false,
	)
	{
		parent::__construct();
	}
}
