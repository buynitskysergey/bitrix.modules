<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\SmartInvoice;

use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\SmartInvoice;

final class AddCommand extends AddItemCommand
{
	public function __construct(
		SmartInvoice $smartInvoice,
		int $userId,
	)
	{
		parent::__construct($smartInvoice, $userId);
	}

	public function getItem(): SmartInvoice
	{
		return $this->item;
	}
}
