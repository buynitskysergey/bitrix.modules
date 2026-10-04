<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Category;

use Bitrix\Main\Entity\EntityCollection;

/**
 * The categories (pipelines) of one entity type, in the order the domain keeps them in.
 *
 * @extends EntityCollection<Category>
 */
final class CategoryCollection extends EntityCollection
{
	public function first(): ?Category
	{
		return $this->items[0] ?? null;
	}

	/** @return Category[] */
	public function getAll(): array
	{
		return $this->items;
	}

	protected static function getEntityClass(): string
	{
		return Category::class;
	}
}
