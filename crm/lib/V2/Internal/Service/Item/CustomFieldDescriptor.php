<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item;

/**
 * @internal
 */
class CustomFieldDescriptor
{
	/**
	 * @param int[]|null $entityTypesId
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $type,
		public readonly bool $isMultiple = false,
		public readonly bool $isRequired = false,
		public readonly bool $isSearchable = false,
		public readonly bool $isFilterable = false,
		public readonly bool $isSortable = false,
		public readonly ?array $entityTypesId = null,
		public readonly ?string $statusTypeId = null,
		public readonly ?int $iBlockId = null,
	)
	{
	}
}
