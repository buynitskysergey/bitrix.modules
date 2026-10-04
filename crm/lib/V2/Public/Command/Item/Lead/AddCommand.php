<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\Lead;

use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Lead;

final class AddCommand extends AddItemCommand
{
	public function __construct(
		Lead $lead,
		int $userId,
	)
	{
		parent::__construct($lead, $userId);
	}

	public function getItem(): Lead
	{
		return $this->item;
	}
}
