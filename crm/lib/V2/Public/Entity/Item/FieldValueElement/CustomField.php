<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement;

use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

final readonly class CustomField implements FieldValueElementInterface
{
	public function __construct(
		private string $fieldName,
		private int|float|string|bool|Date|DateTime|ItemId $fieldValue,
	)
	{
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function getFieldValue(): int|float|string|bool|Date|DateTime|ItemId
	{
		return $this->fieldValue;
	}
}
