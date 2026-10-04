<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement;

use Bitrix\Crm\V2\Public\Entity\Item\AbstractMultifieldValue;

final readonly class Multifield implements FieldValueElementInterface
{
	public function __construct(
		private string $fieldName,
		private AbstractMultifieldValue $fieldValue,
	)
	{
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function getFieldValue(): AbstractMultifieldValue
	{
		return $this->fieldValue;
	}
}
