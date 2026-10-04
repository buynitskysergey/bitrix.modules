<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item;

/**
 * Describes a single field of a CRM Item entity.
 * Maps camelCase V2 name to ORM/DB column name.
 * @internal
 */
class FieldDescriptor
{
	public function __construct(
		public readonly string $name,
		public readonly string $ormName,
		public readonly FieldType $type,
		public readonly bool $readonly = false,
	)
	{
	}
}
