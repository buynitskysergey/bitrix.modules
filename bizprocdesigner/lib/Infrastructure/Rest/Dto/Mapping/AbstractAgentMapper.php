<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Rest\V3\Dto\Mapping\Mapper;

/**
 * Shared reading of a select for the mappers of the external agent contour.
 *
 * A structured select names a plain field by value and a field asked for a level deeper by key, so both
 * forms mean "requested" - the same canon the core follows in Mapper::autoMapRelations(). The sub-list
 * under such a key belongs to the nested resource and is handed over untouched: every level filters by
 * its own list exactly once, and the mapper of that level is the one that applies it.
 */
abstract class AbstractAgentMapper extends Mapper
{
	protected function isRequested(string $propertyName, array $fields): bool
	{
		return $fields === []
			|| in_array($propertyName, $fields, true)
			|| array_key_exists($propertyName, $fields)
		;
	}

	/**
	 * Fields requested for a nested resource, empty for "all fields".
	 *
	 * @return array<int|string, mixed>
	 */
	protected function nestedFields(string $propertyName, array $fields): array
	{
		$nested = $fields[$propertyName] ?? null;

		return is_array($nested) ? $nested : [];
	}
}
