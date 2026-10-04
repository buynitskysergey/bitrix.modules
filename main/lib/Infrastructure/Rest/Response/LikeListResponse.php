<?php

namespace Bitrix\Main\Infrastructure\Rest\Response;

use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\Response;

class LikeListResponse extends ListResponse
{
	public function __construct(
		public DtoCollection $items,
		public ?int $total = null,
	)
	{
		parent::__construct($this->items);
	}
}
