<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Main\Entity\EntityCollection;

/**
 * @extends EntityCollection<EmailValue>
 */
final class EmailCollection extends EntityCollection
{
	/** @return EmailValue[] */
	public function getAll(): array
	{
		return $this->items;
	}

	protected static function getEntityClass(): string
	{
		return EmailValue::class;
	}
}
