<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item;

/**
 * Policy for {@see AbstractItemProvider::withAccessCheck()} — what to do with items the
 * current user can't access.
 */
enum AccessMode: string
{
	/**
	 * Items the user can't read are excluded from the result. `getById()` returns `null`,
	 * `getList()` joins permissions at the DB level (limit/offset are honoured against the
	 * filtered set).
	 */
	case Filter = 'filter';

	/**
	 * Items the user can't read are still returned but flagged via
	 * {@see \Bitrix\Crm\V2\Public\Entity\Item\Item::markAsRestricted()} — useful for cards,
	 * timelines and other UIs that need to show a placeholder instead of hiding the row.
	 */
	case Restricted = 'restricted';
}
