<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NotEmpty extends \Bitrix\Main\Validation\Rule\NotEmpty
{
	public static function __set_state(array $array): static
	{
		return new static(
			allowZero: $array['allowZero'] ?? false,
			allowSpaces: $array['allowSpaces'] ?? false,
			errorMessage: $array['errorMessage'] ?? null,
			groups: $array['groups'] ?? [],
		);
	}
}
