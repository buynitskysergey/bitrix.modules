<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\Company;

use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Company;

final class UpdateCommand extends UpdateItemCommand
{
	public function __construct(
		Company $company,
		int $userId,
	)
	{
		parent::__construct($company, $userId);
	}

	public function getItem(): Company
	{
		return $this->item;
	}
}
