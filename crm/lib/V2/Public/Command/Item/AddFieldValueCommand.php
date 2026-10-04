<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item;

use Bitrix\Crm\V2\Internal\Service\Item\Handler\AddFieldValueCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement\FieldValueElementInterface;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Main\Result;

final class AddFieldValueCommand extends AbstractItemCommand
{
	public function __construct(
		private readonly ItemId $itemId,
		private readonly FieldValueElementInterface $fieldValueElement,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getItemId(): ItemId
	{
		return $this->itemId;
	}

	public function getFieldValueElement(): FieldValueElementInterface
	{
		return $this->fieldValueElement;
	}

	protected function execute(): Result
	{
		return (new AddFieldValueCommandHandler())->handle($this);
	}
}
