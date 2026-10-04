<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\Deal;

use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Deal;

final class AddCommand extends AddItemCommand
{
	public function __construct(
		Deal $deal,
		int $userId,
	)
	{
		parent::__construct($deal, $userId);
	}

	public function getItem(): Deal
	{
		return $this->item;
	}
}
