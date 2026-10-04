<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item\Exception;

use Bitrix\Main\ArgumentException;

final class UnknownSelectFieldException extends ArgumentException
{
	public function __construct(private readonly string $fieldName)
	{
		parent::__construct('Unknown select field: ' . $fieldName, 'select');
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}
}
