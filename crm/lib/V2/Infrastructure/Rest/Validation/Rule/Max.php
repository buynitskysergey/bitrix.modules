<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Max extends \Bitrix\Main\Validation\Rule\Max
{
	public static function __set_state(array $array): static
	{
		return new static(
			max: $array['max'] ?? PHP_INT_MAX,
			errorMessage: $array['errorMessage'] ?? null,
			groups: $array['groups'] ?? [],
		);
	}
}
