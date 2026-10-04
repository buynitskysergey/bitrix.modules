<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Category;

/**
 * A category (pipeline) as the storage boundary hands it upwards.
 *
 * `isSystem` and `code` are `null` when the entity type has no such attribute at all - Deal drops
 * both from its category field model ({@see \Bitrix\Crm\Service\Factory\Deal::getCategoryFieldsInfo()}),
 * while the legacy entity still answers `false` / `''`. `false` / `''` therefore keep their own
 * meaning: the attribute exists and is not set.
 *
 * @internal
 */
final readonly class CategoryData
{
	public function __construct(
		public int $id,
		public int $entityTypeId,
		public string $name,
		public int $sort,
		public bool $isDefault,
		public ?bool $isSystem,
		public ?string $code,
	)
	{
	}
}
