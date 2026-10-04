<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\Quote;

use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Quote;

final class UpdateCommand extends UpdateItemCommand
{
	public function __construct(
		Quote $quote,
		int $userId,
	)
	{
		parent::__construct($quote, $userId);
	}

	public function getItem(): Quote
	{
		return $this->item;
	}
}
