<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Main\Entity\EntityCollection;

/**
 * @extends EntityCollection<ImValue>
 */
final class ImCollection extends EntityCollection
{
	/** @return ImValue[] */
	public function getAll(): array
	{
		return $this->items;
	}

	protected static function getEntityClass(): string
	{
		return ImValue::class;
	}
}
