<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item;

use Bitrix\Crm\V2\Internal\Service\Item\Handler\UpdateItemCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Main\Result;

class UpdateItemCommand extends AbstractItemCommand
{
	public function __construct(
		protected readonly Item $item,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getItem(): Item
	{
		return $this->item;
	}

	protected function execute(): Result
	{
		return (new UpdateItemCommandHandler())->handle($this);
	}
}
