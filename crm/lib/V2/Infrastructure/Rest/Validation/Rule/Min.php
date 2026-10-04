<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Min extends \Bitrix\Main\Validation\Rule\Min
{
	public static function __set_state(array $array): static
	{
		return new static(
			min: $array['min'] ?? PHP_INT_MIN,
			errorMessage: $array['errorMessage'] ?? null,
			groups: $array['groups'] ?? [],
		);
	}
}
