<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Length extends \Bitrix\Main\Validation\Rule\Length
{
	public static function __set_state(array $array): static
	{
		return new static(
			min: $array['min'] ?? null,
			max: $array['max'] ?? null,
			errorMessage: $array['errorMessage'] ?? null,
			groups: $array['groups'] ?? [],
		);
	}
}
