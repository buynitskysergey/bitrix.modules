<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\Lead;

use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Lead;

final class UpdateCommand extends UpdateItemCommand
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
