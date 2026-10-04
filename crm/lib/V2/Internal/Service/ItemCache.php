<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service;

use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * Central in-memory cache for V2 Items, shared by every cached Provider within the current hit.
 *
 * Keyed by `(entityType, id)` — one entry per item, regardless of the select it was read with.
 * Each entry keeps its own deep snapshot: {@see set()} stores a clone and {@see get()} hands out
 * a clone, so neither the caller that populated the cache nor a later consumer can mutate the
 * cached state. An entry also remembers which scalar ORM columns are populated on the snapshot
 * (relations are self-describing on the Item: a null collection means "not loaded"), letting
 * Providers serve subset selects from the cache and fetch only the missing fields.
 *
 * Custom fields (`UF_*`) live in a non-nullable bag on the Item, so a null collection can't
 * signal "not loaded" for them - the entry tracks their loadedness separately: the set of
 * populated UF names plus an "all UF loaded" flag (set by {@see ItemSelect::withCustomFields()}
 * reads). Providers use it to serve a UF subset from the cache and fetch only the missing UF.
 *
 * Snapshots hold raw, pre-access-check data - read permissions and per-user UF visibility are
 * applied by Providers at return time, so the same entry can be served in full to one user and
 * as a restricted stub (or with a narrower UF set) to another. Restricted stubs themselves are
 * never stored.
 *
 * There is no full flush and no size limit: the cache lives only for the current request and is
 * invalidated point-wise on writes — by V2 command handlers and by legacy
 * {@see \Bitrix\Crm\Service\Operation} alike.
 *
 * @internal
 */
final class ItemCache
{
	private static ?self $instance = null;

	/** @var array<int, array<int, Item>> entityTypeId => id => snapshot */
	private array $items = [];

	/** @var array<int, array<int, string[]>> entityTypeId => id => loaded scalar ORM column names */
	private array $loadedFields = [];

	/** @var array<int, array<int, string[]>> entityTypeId => id => loaded custom-field (`UF_*`) names */
	private array $loadedCustomFields = [];

	/** @var array<int, array<int, bool>> entityTypeId => id => "every UF of the type is loaded" */
	private array $allCustomFieldsLoaded = [];

	/** @var array<int, array<int, bool>> entityTypeId => id => "parent bindings are loaded" */
	private array $parentsLoaded = [];

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	/**
	 * Returns a deep clone of the cached snapshot, or null when the item is not cached.
	 */
	public function get(EntityType $entityType, int $id): ?Item
	{
		$item = $this->items[$entityType->getId()][$id] ?? null;

		return $item === null ? null : clone $item;
	}

	/**
	 * Scalar ORM columns populated on the cached snapshot, or null when the item is not cached.
	 * Relations are not tracked here — on the Item itself a null collection means "not loaded".
	 *
	 * @return string[]|null
	 */
	public function getLoadedOrmFields(EntityType $entityType, int $id): ?array
	{
		return $this->loadedFields[$entityType->getId()][$id] ?? null;
	}

	/**
	 * Custom-field (`UF_*`) names populated on the cached snapshot, or null when the item is not
	 * cached. Independent of {@see getLoadedOrmFields()}: UF loadedness can't be read off the Item
	 * (its bag is never null), so the entry tracks it here. See {@see areAllCustomFieldsLoaded()}
	 * for the "loaded every UF of the type" shortcut.
	 *
	 * @return string[]|null
	 */
	public function getLoadedCustomFields(EntityType $entityType, int $id): ?array
	{
		return $this->loadedCustomFields[$entityType->getId()][$id] ?? null;
	}

	/**
	 * True when the cached snapshot was populated with every UF of the type (a
	 * {@see \Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect::withCustomFields()} read), so any
	 * UF subset is served from the cache without a fetch. False when not cached or only some UF are
	 * loaded.
	 */
	public function areAllCustomFieldsLoaded(EntityType $entityType, int $id): bool
	{
		return $this->allCustomFieldsLoaded[$entityType->getId()][$id] ?? false;
	}

	/**
	 * True when the cached snapshot was populated with parent bindings (a
	 * {@see \Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect::withParents()} read). Parents are
	 * all-or-nothing (every parent type at once), so a single flag suffices - unlike UF, there is no
	 * per-type subset. False when not cached or parents were not loaded.
	 */
	public function areParentsLoaded(EntityType $entityType, int $id): bool
	{
		return $this->parentsLoaded[$entityType->getId()][$id] ?? false;
	}

	/**
	 * Stores a deep clone of $item as the snapshot for `(entityType, id)`, replacing any previous
	 * entry. $loadedOrmFields must describe exactly which scalar ORM columns are populated on
	 * $item, and $loadedCustomFields / $allCustomFieldsLoaded which UF are - the calling Provider
	 * keeps that bookkeeping. Restricted stubs are silently rejected: the cache holds only raw data.
	 *
	 * @param string[] $loadedOrmFields
	 * @param string[] $loadedCustomFields raw `UF_*` names populated on $item
	 * @param bool $allCustomFieldsLoaded whether every UF of the type is populated
	 * @param bool $parentsLoaded whether parent bindings are populated on $item
	 */
	public function set(
		EntityType $entityType,
		int $id,
		Item $item,
		array $loadedOrmFields,
		array $loadedCustomFields = [],
		bool $allCustomFieldsLoaded = false,
		bool $parentsLoaded = false,
	): void
	{
		if (!$item->canRead())
		{
			return;
		}

		$snapshot = clone $item;
		// A snapshot represents persisted state; pending caller-side change marks make no sense on it.
		$snapshot->resetChangedFields();

		$this->items[$entityType->getId()][$id] = $snapshot;
		$this->loadedFields[$entityType->getId()][$id] = array_values(array_unique($loadedOrmFields));
		$this->loadedCustomFields[$entityType->getId()][$id] = array_values(array_unique($loadedCustomFields));
		$this->allCustomFieldsLoaded[$entityType->getId()][$id] = $allCustomFieldsLoaded;
		$this->parentsLoaded[$entityType->getId()][$id] = $parentsLoaded;
	}

	public function invalidate(EntityType $entityType, int $id): void
	{
		unset(
			$this->items[$entityType->getId()][$id],
			$this->loadedFields[$entityType->getId()][$id],
			$this->loadedCustomFields[$entityType->getId()][$id],
			$this->allCustomFieldsLoaded[$entityType->getId()][$id],
			$this->parentsLoaded[$entityType->getId()][$id],
		);
	}

	/**
	 * @param int[] $ids
	 */
	public function invalidateMany(EntityType $entityType, array $ids): void
	{
		foreach ($ids as $id)
		{
			$this->invalidate($entityType, (int)$id);
		}
	}
}
