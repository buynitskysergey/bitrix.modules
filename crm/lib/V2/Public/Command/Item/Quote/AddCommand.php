<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\Quote;

use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Quote;

final class AddCommand extends AddItemCommand
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
