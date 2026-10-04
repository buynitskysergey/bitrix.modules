<?php

namespace Bitrix\Main\Infrastructure\Rest\Response;

use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\Response;

class HistoryListResponse extends Response
{
	public function __construct(
		public DtoCollection $items,
		public ?int $total = null,
		public ?int $next = null,
	)
	{
	}
}
