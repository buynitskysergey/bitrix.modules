<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ElementsType extends \Bitrix\Main\Validation\Rule\ElementsType
{
	public static function __set_state(array $array): static
	{
		return new static(
			typeEnum: $array['typeEnum'] ?? null,
			className: $array['className'] ?? null,
			errorMessage: $array['errorMessage'] ?? null,
			groups: $array['groups'] ?? [],
		);
	}
}
