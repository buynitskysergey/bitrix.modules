<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\Deal;

use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Deal;

final class UpdateCommand extends UpdateItemCommand
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
