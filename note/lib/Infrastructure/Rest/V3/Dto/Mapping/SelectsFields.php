<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping;

/**
 * Narrowing an answer to the fields the caller asked for.
 *
 * The canon validates `select` against the DTO and hands the mapper the list, but it fills nothing in
 * by itself - a hand-written mapper decides field by field, the way the core's own manual mappers do.
 * An empty list is not "no fields" but "the caller said nothing", and the whole default answer is
 * returned; that is what keeps existing callers untouched.
 */
trait SelectsFields
{
	/**
	 * @param string[]|array<string, mixed> $fields
	 */
	protected function isFieldSelected(string $field, array $fields, bool $defaultIncluded = true): bool
	{
		if (empty($fields))
		{
			return $defaultIncluded;
		}

		// A nested field arrives as a key with its own sub-list, a scalar one as a plain value.
		return in_array($field, $fields, true) || array_key_exists($field, $fields);
	}
}
