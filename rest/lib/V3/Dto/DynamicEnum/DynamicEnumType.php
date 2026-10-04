<?php

namespace Bitrix\Rest\V3\Dto\DynamicEnum;

enum DynamicEnumType: string
{
	case String = 'string';
	case Int = 'int';

	public function toOpenApiType(): string
	{
		return match ($this)
		{
			self::String => 'string',
			self::Int => 'integer',
		};
	}

	public function matchesPhpType(string $phpType): bool
	{
		$phpType = strtolower($phpType);

		return match ($this)
		{
			self::String => $phpType === 'string',
			self::Int => $phpType === 'int' || $phpType === 'integer',
		};
	}
}
