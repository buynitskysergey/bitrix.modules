<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item;

use Bitrix\Crm\V2\Internal\Service\Item\Handler\DeleteItemCommandHandler;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Main\Result;

final class DeleteItemCommand extends AbstractItemCommand
{
	public function __construct(
		private readonly ItemId $itemId,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getItemId(): ItemId
	{
		return $this->itemId;
	}

	protected function execute(): Result
	{
		return (new DeleteItemCommandHandler())->handle($this);
	}
}
