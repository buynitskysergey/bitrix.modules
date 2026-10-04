<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\UI\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Service\License\LicenseService;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

// Not final: unit tests subclass to override the LicenseService seam.
class CollectionProvider extends BaseProvider
{
	public const ENTITY_ID = 'note-collection';

	private const COLLECTION_TAB_ID = 'note-collection-tab';
	private const RECENT_LIMIT = 50;
	private const SEARCH_LIMIT = 50;
	private const MIN_QUERY_LENGTH = 1;

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options = $options;
	}

	public function isAvailable(): bool
	{
		// Tariff/tool gate before ACL: same denial as an ACL failure.
		if ($this->createLicenseService()->isAccessBlocked())
		{
			return false;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return false;
		}

		// Gate the global selector behind the Notes tool ACL — a user without note_access
		// must not enumerate collection names through the entity-selector.
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_ACCESS);
	}

	protected function createLicenseService(): LicenseService
	{
		return new LicenseService();
	}

	public function getItems(array $ids): array
	{
		$ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0));
		if (empty($ids))
		{
			return [];
		}

		$allowedIds = $this->filterByManageAccess($ids);
		if (empty($allowedIds))
		{
			return [];
		}

		$rows = CollectionTable::query()
			->setSelect(['ID', 'NAME'])
			->whereIn('ID', $allowedIds)
			->where('IS_ARCHIVED', 'N')
			->setOrder(['NAME' => 'ASC'])
			->exec()
		;

		$items = [];
		while ($row = $rows->fetch())
		{
			$items[] = $this->makeItem((int)$row['ID'], (string)$row['NAME']);
		}

		return $items;
	}

	public function getPreselectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	public function fillDialog(Dialog $dialog): void
	{
		// Register the tab first so it appears even if the user has no accessible collections.
		$dialog->addTab($this->makeTab());

		$dialog->loadPreselectedItems();

		$query = CollectionTable::query()
			->setSelect(['ID', 'NAME'])
			->where('IS_ARCHIVED', 'N')
			->setOrder(['UPDATED_AT' => 'DESC', 'ID' => 'DESC'])
			->setLimit(self::RECENT_LIMIT)
		;

		if (!$this->isAdmin())
		{
			$collectionIds = $this->getManageAccessibleCollectionIds();
			if (empty($collectionIds))
			{
				return;
			}
			$query->whereIn('ID', $collectionIds);
		}

		$rows = $query->exec();
		while ($row = $rows->fetch())
		{
			$dialog->addRecentItem($this->makeItem((int)$row['ID'], (string)$row['NAME']));
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$queryText = trim($searchQuery->getQuery());
		if (mb_strlen($queryText) < self::MIN_QUERY_LENGTH)
		{
			return;
		}

		$dialog->addTab($this->makeTab());

		// Left-anchored LIKE so the index can be used.
		$query = CollectionTable::query()
			->setSelect(['ID', 'NAME'])
			->where('IS_ARCHIVED', 'N')
			->whereLike('NAME', $queryText . '%')
			->setOrder(['NAME' => 'ASC'])
			->setLimit(self::SEARCH_LIMIT)
		;

		if (!$this->isAdmin())
		{
			$collectionIds = $this->getManageAccessibleCollectionIds();
			if (empty($collectionIds))
			{
				return;
			}
			$query->whereIn('ID', $collectionIds);
		}

		$rows = $query->exec();
		$count = 0;
		while ($row = $rows->fetch())
		{
			$dialog->addItem($this->makeItem((int)$row['ID'], (string)$row['NAME']));
			$count++;
		}

		if ($count >= self::SEARCH_LIMIT)
		{
			$searchQuery->setCacheable(false);
		}
	}

	private function makeItem(int $id, string $title): Item
	{
		return new Item([
			'id' => $id,
			'entityId' => self::ENTITY_ID,
			'tabs' => [self::COLLECTION_TAB_ID],
			'title' => $title,
			'supertitle' => Loc::getMessage('NOTE_MENTION_SELECTOR_CATEGORY_COLLECTION'),
			'avatar' => self::collectionItemIconDataUri(),
			'avatarOptions' => [
				// DS accent token; resolves against the popup design-system context (light/dark)
				// since entity-selector writes bgColor as inline style on an element inside that scope.
				'bgColor' => 'var(--ui-color-design-filled-bg)',
				// Tuned for compactView (22px box): icon-set tokens render ~18px icon with ~14px visible glyph;
				// match that visual size so the collection icon aligns with document/task glyphs.
				'bgSize' => '14px',
			],
		]);
	}

	private function makeTab(): Tab
	{
		return new Tab([
			'id' => self::COLLECTION_TAB_ID,
			'title' => Loc::getMessage('NOTE_ENTITY_SELECTOR_COLLECTION_TAB_TITLE'),
			// Data-URI is treated as a URL by the Tab renderer and applied via CSS mask-image (monochrome, adopts tab color).
			'icon' => ['default' => self::collectionTabIconDataUri()],
		]);
	}

	/**
	 * Returns a data-URI for the collection glyph with white fill, suitable for use as avatar background-image.
	 * Unlike collectionTabIconDataUri(), the fill is #FFFFFF so the glyph is visible on a coloured background.
	 */
	private static function collectionItemIconDataUri(): string
	{
		// Source: note/install/js/note/sidebar/src/images/collection.svg — path fill set to #FFFFFF.
		return 'data:image/svg+xml,%3Csvg%20width=%2216%22%20height=%2215%22%20viewBox=%220%200%2016%2015%22%20xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cpath%20fill=%22%23FFFFFF%22%20fill-rule=%22evenodd%22%20clip-rule=%22evenodd%22%20d=%22M10.7275%200.0684879C11.7943%20-0.217133%2012.8909%200.416726%2013.1768%201.48353L15.752%2011.0929C16.0378%2012.1597%2015.4046%2013.2571%2014.3379%2013.5431L13.2529%2013.8331C12.186%2014.119%2011.0896%2013.486%2010.8037%2012.4191L9.0459%205.85853V12.215C9.0459%2013.3194%208.15031%2014.2148%207.0459%2014.215H5.92285C5.37723%2014.215%204.8833%2013.996%204.52246%2013.6417C4.16165%2013.9957%203.66833%2014.2149%203.12305%2014.215H2C0.895431%2014.215%200%2013.3195%200%2012.215V2.26575C9.86933e-05%201.16127%200.895491%200.265754%202%200.265754H3.12305C3.66799%200.265846%204.1617%200.484458%204.52246%200.838019C4.88324%200.484148%205.37757%200.265754%205.92285%200.265754H7.0459C7.6817%200.265862%208.24693%200.563924%208.61328%201.0265C8.86938%200.712714%209.2215%200.472377%209.64258%200.359504L10.7275%200.0684879ZM2%201.46497C1.55823%201.46497%201.19932%201.82401%201.19922%202.26575V12.215C1.19922%2012.6568%201.55817%2013.0158%202%2013.0158H3.12305C3.56471%2013.0156%203.92285%2012.6567%203.92285%2012.215V2.26575C3.92275%201.82413%203.56465%201.46516%203.12305%201.46497H2ZM5.92285%201.46497C5.48434%201.46497%205.12741%201.81873%205.12207%202.25599C5.12209%202.25924%205.12305%202.2625%205.12305%202.26575V12.215C5.12305%2012.2182%205.12209%2012.2215%205.12207%2012.2247C5.12722%2012.6622%205.48422%2013.0158%205.92285%2013.0158H7.0459C7.48757%2013.0156%207.8457%2012.6567%207.8457%2012.215V2.26575C7.8456%201.82413%207.48751%201.46516%207.0459%201.46497H5.92285ZM12.0176%201.79407C11.9033%201.36742%2011.4648%201.11354%2011.0381%201.22767L9.95312%201.51771C9.52651%201.63202%209.27267%202.07155%209.38672%202.49818L11.9619%2012.1085C12.0762%2012.5352%2012.5157%2012.7891%2012.9424%2012.6749L14.0273%2012.3839C14.4539%2012.2694%2014.7071%2011.8301%2014.5928%2011.4034L12.0176%201.79407Z%22/%3E%3C/svg%3E';
	}

	/**
	 * Returns a data-URI for the note collection glyph (three-column stack icon, 16x15).
	 * The Tab renderer detects a non-token string and uses it as mask-image, so fill color is irrelevant.
	 */
	private static function collectionTabIconDataUri(): string
	{
		// Source: note/install/js/note/sidebar/src/images/collection.svg
		return 'data:image/svg+xml,%3Csvg%20width=%2216%22%20height=%2215%22%20viewBox=%220%200%2016%2015%22%20xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cpath%20fill-rule=%22evenodd%22%20clip-rule=%22evenodd%22%20d=%22M10.7275%200.0684879C11.7943%20-0.217133%2012.8909%200.416726%2013.1768%201.48353L15.752%2011.0929C16.0378%2012.1597%2015.4046%2013.2571%2014.3379%2013.5431L13.2529%2013.8331C12.186%2014.119%2011.0896%2013.486%2010.8037%2012.4191L9.0459%205.85853V12.215C9.0459%2013.3194%208.15031%2014.2148%207.0459%2014.215H5.92285C5.37723%2014.215%204.8833%2013.996%204.52246%2013.6417C4.16165%2013.9957%203.66833%2014.2149%203.12305%2014.215H2C0.895431%2014.215%200%2013.3195%200%2012.215V2.26575C9.86933e-05%201.16127%200.895491%200.265754%202%200.265754H3.12305C3.66799%200.265846%204.1617%200.484458%204.52246%200.838019C4.88324%200.484148%205.37757%200.265754%205.92285%200.265754H7.0459C7.6817%200.265862%208.24693%200.563924%208.61328%201.0265C8.86938%200.712714%209.2215%200.472377%209.64258%200.359504L10.7275%200.0684879ZM2%201.46497C1.55823%201.46497%201.19932%201.82401%201.19922%202.26575V12.215C1.19922%2012.6568%201.55817%2013.0158%202%2013.0158H3.12305C3.56471%2013.0156%203.92285%2012.6567%203.92285%2012.215V2.26575C3.92275%201.82413%203.56465%201.46516%203.12305%201.46497H2ZM5.92285%201.46497C5.48434%201.46497%205.12741%201.81873%205.12207%202.25599C5.12209%202.25924%205.12305%202.2625%205.12305%202.26575V12.215C5.12305%2012.2182%205.12209%2012.2215%205.12207%2012.2247C5.12722%2012.6622%205.48422%2013.0158%205.92285%2013.0158H7.0459C7.48757%2013.0156%207.8457%2012.6567%207.8457%2012.215V2.26575C7.8456%201.82413%207.48751%201.46516%207.0459%201.46497H5.92285ZM12.0176%201.79407C11.9033%201.36742%2011.4648%201.11354%2011.0381%201.22767L9.95312%201.51771C9.52651%201.63202%209.27267%202.07155%209.38672%202.49818L11.9619%2012.1085C12.0762%2012.5352%2012.5157%2012.7891%2012.9424%2012.6749L14.0273%2012.3839C14.4539%2012.2694%2014.7071%2011.8301%2014.5928%2011.4034L12.0176%201.79407Z%22/%3E%3C/svg%3E';
	}

	private function isAdmin(): bool
	{
		return PortalAdmin::isCurrentUserAdmin();
	}

	// 'view' => LEVEL_VIEW, anything else (or missing) => LEVEL_MANAGE (backward-compatible default).
	private function accessThreshold(): int
	{
		return ($this->options['accessLevel'] ?? 'manage') === 'view'
			? CollectionAccessService::LEVEL_VIEW
			: CollectionAccessService::LEVEL_MANAGE;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function filterByManageAccess(array $ids): array
	{
		if ($this->isAdmin())
		{
			return $ids;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return [];
		}

		// '*' picks up public-policy rows; symmetric with CollectionMentionResolver and the search path.
		// Do not bail on empty personal codes — public-policy collections must stay reachable.
		$accessCodes = array_values(array_unique([
			...CollectionAccessService::buildUserAccessCodes($userId),
			'*',
		]));

		$levels = CollectionAccessService::batchGetUserLevels($ids, $accessCodes);

		$threshold = $this->accessThreshold();
		$allowed = [];
		foreach ($levels as $cid => $level)
		{
			if ((int)$level >= $threshold)
			{
				$allowed[] = (int)$cid;
			}
		}

		return $allowed;
	}

	/**
	 * Non-admin only — admin paths skip this and query CollectionTable directly with a LIMIT.
	 *
	 * @return int[]
	 */
	private function getManageAccessibleCollectionIds(): array
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return [];
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		if (empty($accessCodes))
		{
			return [];
		}

		$levels = CollectionAccessService::getAllUserLevels($accessCodes);
		$effective = $levels['effective'] ?? [];

		$threshold = $this->accessThreshold();
		$ids = [];
		foreach ($effective as $cid => $level)
		{
			if ((int)$level >= $threshold)
			{
				$ids[] = (int)$cid;
			}
		}

		return $ids;
	}
}
