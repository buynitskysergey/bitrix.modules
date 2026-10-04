<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Main\Entity\EntityCollection;

/**
 * Generic collection for any Item subclass.
 *
 * Homogeneity note: the {@see EntityCollection::add()} runtime guard only checks that each element
 * `is_a(Item::class)` — because {@see Item} is the abstract base, a single collection may hold a mix
 * of concrete Item subclasses (e.g. Deal and Contact together). The @template T below is a static-analysis
 * hint for the intended element type; it is NOT enforced at runtime. Callers must not assume homogeneity.
 *
 * @template T of Item
 * @extends EntityCollection<T>
 */
class ItemCollection extends EntityCollection
{
	/** @return T|null */
	public function first(): ?Item
	{
		return $this->items[0] ?? null;
	}

	/** @return T[] */
	public function getAll(): array
	{
		return $this->items;
	}

	protected static function getEntityClass(): string
	{
		return Item::class;
	}
}
