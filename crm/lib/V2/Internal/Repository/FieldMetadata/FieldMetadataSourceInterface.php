<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\FieldMetadata;

use Bitrix\Crm\V2\Internal\Entity\FieldMetadata\FieldMetadata;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * @internal
 */
interface FieldMetadataSourceInterface
{
	/**
	 * @return FieldMetadata[]
	 */
	public function getAll(EntityType $entityType): array;

	public function getByName(EntityType $entityType, string $name): ?FieldMetadata;
}
