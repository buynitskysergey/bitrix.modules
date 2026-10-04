<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement;

interface FieldValueElementInterface
{
	public function getFieldName(): string;

	public function getFieldValue(): mixed;
}
