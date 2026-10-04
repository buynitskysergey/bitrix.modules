<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\SmartProcess;

use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\SmartProcess;

final class UpdateCommand extends UpdateItemCommand
{
	public function __construct(
		SmartProcess $smartProcess,
		int $userId,
	)
	{
		parent::__construct($smartProcess, $userId);
	}

	public function getItem(): SmartProcess
	{
		return $this->item;
	}
}
