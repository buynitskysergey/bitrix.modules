<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\SmartProcess;

use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\SmartProcess;

final class AddCommand extends AddItemCommand
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
