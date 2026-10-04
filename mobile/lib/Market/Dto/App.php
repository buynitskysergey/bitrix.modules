<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class App extends Dto
{
	public function __construct(
		public string $code = '',
		public string $title = '',
	)
	{
		parent::__construct();
	}
}
