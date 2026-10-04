<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item\Exception;

use Bitrix\Main\ArgumentException;

final class UnknownSortFieldException extends ArgumentException
{
	public function __construct(private readonly string $fieldName)
	{
		parent::__construct('Unknown sort field: ' . $fieldName, 'sort');
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}
}
