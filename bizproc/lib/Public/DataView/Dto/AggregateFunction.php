<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

use Bitrix\Bizproc\FieldType;

enum AggregateFunction: string
{
	case Sum = 'SUM';
	case Count = 'COUNT';
	case Avg = 'AVG';
	case Min = 'MIN';
	case Max = 'MAX';

	public function requiresNumericColumn(): bool
	{
		return $this === self::Sum || $this === self::Avg;
	}

	public function resultType(string $columnType): string
	{
		return match ($this)
		{
			self::Count => FieldType::INT,
			self::Avg => FieldType::DOUBLE,
			self::Sum, self::Min, self::Max => $columnType,
		};
	}
}
