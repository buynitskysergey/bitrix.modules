<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item\Exception;

use Bitrix\Main\ArgumentException;

final class UnknownFilterFieldException extends ArgumentException
{
	public function __construct(private readonly string $fieldName)
	{
		parent::__construct('Unknown filter field: ' . $fieldName, 'filter');
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}
}
