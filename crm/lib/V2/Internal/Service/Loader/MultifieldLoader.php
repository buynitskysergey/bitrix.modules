<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Multifield\Collection as LegacyMultifieldCollection;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\MultifieldMapper;
use Bitrix\Crm\V2\Public\Entity\Item\EmailCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ImCollection;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneCollection;
use Bitrix\Crm\V2\Public\Entity\Item\WebCollection;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;

/**
 * Loads multifield collections (phones / emails / webs / ims) onto V2 Items.
 *
 * Goes through {@see \Bitrix\Crm\Service\MultifieldStorage} so we get the legacy storage's
 * built-in cache (per-process, keyed by ItemIdentifier). Always installs a non-null collection
 * for every requested type so the Provider's "null = not loaded, empty = no values" contract
 * holds even when an entity has no multifields at all.
 *
 * @internal
 */
final class MultifieldLoader
{
	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadAnyMultifield())
		{
			return;
		}

		// Persisted-only filter; transient/draft Items have no row to load.
		$persisted = array_values(array_filter($items, static fn(Item $i) => $i->getId() !== null));
		if (empty($persisted))
		{
			return;
		}

		$entityTypeId = $persisted[0]->getEntityType()->getId();
		$ownerIds = array_map(static fn(Item $i) => (int)$i->getId(), $persisted);

		// Warm the storage's bulk cache so the per-item get() calls below are O(1).
		$storage = Container::getInstance()->getMultifieldStorage();
		$storage->warmupCache($entityTypeId, $ownerIds);

		foreach ($persisted as $item)
		{
			$collection = $storage->get(new ItemIdentifier($entityTypeId, (int)$item->getId()));
			$this->applyToItem($item, $collection, $select);
		}
	}

	private function applyToItem(Item $item, LegacyMultifieldCollection $fm, ItemSelect $select): void
	{
		if ($select->shouldLoadPhones())
		{
			$item->internalSet(
				Item::phones,
				$this->buildOrEmpty($fm, 'PHONE', PhoneCollection::class),
			);
		}

		if ($select->shouldLoadEmails())
		{
			$item->internalSet(
				Item::emails,
				$this->buildOrEmpty($fm, 'EMAIL', EmailCollection::class),
			);
		}

		if ($select->shouldLoadWebs())
		{
			$item->internalSet(
				Item::webs,
				$this->buildOrEmpty($fm, 'WEB', WebCollection::class),
			);
		}

		if ($select->shouldLoadIms())
		{
			$item->internalSet(
				Item::ims,
				$this->buildOrEmpty($fm, 'IM', ImCollection::class),
			);
		}
	}

	/**
	 * @return PhoneCollection|EmailCollection|WebCollection|ImCollection
	 */
	private function buildOrEmpty(LegacyMultifieldCollection $fm, string $typeId, string $emptyClass): object
	{
		$filtered = $fm->filterByType($typeId);

		return match ($typeId)
		{
			'PHONE' => MultifieldMapper::toPhoneCollection($filtered),
			'EMAIL' => MultifieldMapper::toEmailCollection($filtered),
			'WEB' => MultifieldMapper::toWebCollection($filtered),
			'IM' => MultifieldMapper::toImCollection($filtered),
			default => new $emptyClass(),
		};
	}
}
