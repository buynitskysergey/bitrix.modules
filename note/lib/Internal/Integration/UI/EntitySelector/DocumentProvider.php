<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\UI\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Service\Document\MainDocumentFilter;
use Bitrix\Note\Internal\Service\License\LicenseService;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

// Not final: unit tests subclass to override the LicenseService seam.
class DocumentProvider extends BaseProvider
{
	public const ENTITY_ID = 'note-document';

	private const DOCUMENT_TAB_ID = 'note-document-tab';
	private const MIN_QUERY_LENGTH = 1;
	private const SEARCH_LIMIT = 50;
	// Over-fetch title matches before the per-document VIEW filter so an ACL-heavy
	// result set still fills up to SEARCH_LIMIT (mirrors loadRecentItems).
	private const SEARCH_PREFETCH_LIMIT = 100;
	private const RECENT_PREFETCH_LIMIT = 100;
	private const RECENT_DISPLAY_LIMIT = 20;

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
		// must not enumerate document titles/collections through the entity-selector.
		return AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_ACCESS);
	}

	protected function createLicenseService(): LicenseService
	{
		return new LicenseService();
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$queryText = trim($searchQuery->getQuery());
		if (mb_strlen($queryText) < self::MIN_QUERY_LENGTH)
		{
			return;
		}

		$dialog->addTab($this->makeTab());

		// Mention search = "find a document by its title": a left-anchored TITLE
		// LIKE, NOT full-text. FT depends on the search-index table, so a freshly
		// created / not-yet-indexed / empty document is invisible there
		// ("почему не находится") — a title query finds it regardless of index
		// state. Symmetric with CollectionProvider::doSearch. The 'x%' anchoring
		// is index-backed by IX_NOTE_DOC_TITLE (IS_ARCHIVED, TITLE); on PostgreSQL
		// that index uses varchar_pattern_ops so the prefix LIKE stays sargable.
		// User-typed '%'/'_' pass through as LIKE wildcards as-is: this only
		// affects UX (query intent), not security — the VIEW filter below still
		// gates every row. Same as CollectionProvider::doSearch.
		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID', 'TITLE', 'COLLECTION.NAME'])
			->whereLike('TITLE', $queryText . '%')
			->where('IS_ARCHIVED', 'N')
			->setLimit(self::SEARCH_PREFETCH_LIMIT);
		$this->applyRecencyOrder($query);
		(new RecycleBinFilter())->applyExclusion($query);
		// A collection's main document is not a standalone page: it has no tree node and
		// /note/document/{id}/ cannot open it, so mentioning it would produce a dead link.
		(new MainDocumentFilter())->applyExclusion($query);

		$documents = $query->fetchCollection()->getAll();
		if (empty($documents))
		{
			return;
		}

		// Prefetch-then-filter can under-report for low-access users if ACL-filtering
		// trims below SEARCH_LIMIT even though more matching rows exist past the
		// prefetch window — same accepted tradeoff as loadRecentItems().
		$items = $this->accessibleDocumentItems($documents, self::SEARCH_LIMIT);
		foreach ($items as $item)
		{
			$dialog->addItem($item);
		}

		if (count($documents) >= self::SEARCH_PREFETCH_LIMIT)
		{
			$searchQuery->setCacheable(false);
		}
	}

	/**
	 * Filters documents to those the current user may VIEW and builds items,
	 * capped at $limit. Admins bypass the per-document access check.
	 *
	 * @param \Bitrix\Note\Internal\Model\EO_Document[] $documents
	 * @return Item[]
	 */
	private function accessibleDocumentItems(array $documents, int $limit): array
	{
		$items = [];

		if ($this->isAdmin())
		{
			foreach ($documents as $document)
			{
				$items[] = $this->makeItem(
					(int)$document->getId(),
					(string)$document->getTitle(),
					(string)($document->getCollection()?->getName() ?? ''),
				);
				if (count($items) >= $limit)
				{
					break;
				}
			}

			return $items;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return [];
		}

		$docDescriptors = [];
		foreach ($documents as $document)
		{
			$docDescriptors[] = [
				'id' => (int)$document->getId(),
				'collectionId' => (int)$document->getCollectionId(),
			];
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = DocumentAccessService::batchGetEffectiveLevels($docDescriptors, $accessCodes, $userId);

		foreach ($documents as $document)
		{
			$docId = (int)$document->getId();
			if (($levels[$docId] ?? 0) >= DocumentAccessService::LEVEL_VIEW)
			{
				$items[] = $this->makeItem(
					$docId,
					(string)$document->getTitle(),
					(string)($document->getCollection()?->getName() ?? ''),
				);
				if (count($items) >= $limit)
				{
					break;
				}
			}
		}

		return $items;
	}

	public function getItems(array $ids): array
	{
		$ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0));
		if (empty($ids))
		{
			return [];
		}

		// Exclude archived, recycle-bin and main documents at the query level — the same set
		// doSearch() offers, so a preselected id cannot smuggle in what search never shows.
		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID', 'TITLE', 'COLLECTION.NAME'])
			->whereIn('ID', $ids)
			->where('IS_ARCHIVED', 'N');
		(new RecycleBinFilter())->applyExclusion($query);
		(new MainDocumentFilter())->applyExclusion($query);
		$documents = $query->fetchCollection()->getAll();
		if (empty($documents))
		{
			return [];
		}

		if ($this->isAdmin())
		{
			$items = [];
			foreach ($documents as $document)
			{
				$items[] = $this->makeItem(
					(int)$document->getId(),
					(string)$document->getTitle(),
					(string)($document->getCollection()?->getName() ?? ''),
				);
			}

			return $items;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return [];
		}

		$docDescriptors = [];
		foreach ($documents as $document)
		{
			$docDescriptors[] = [
				'id' => (int)$document->getId(),
				'collectionId' => (int)$document->getCollectionId(),
			];
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = DocumentAccessService::batchGetEffectiveLevels($docDescriptors, $accessCodes, $userId);

		$items = [];
		foreach ($documents as $document)
		{
			$docId = (int)$document->getId();
			if (($levels[$docId] ?? 0) >= DocumentAccessService::LEVEL_VIEW)
			{
				$items[] = $this->makeItem(
					$docId,
					(string)$document->getTitle(),
					(string)($document->getCollection()?->getName() ?? ''),
				);
			}
		}

		return $items;
	}

	public function getPreselectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	public function fillDialog(Dialog $dialog): void
	{
		// Register the tab so it appears in the dialog even before any search query.
		$dialog->addTab($this->makeTab());

		$dialog->loadPreselectedItems();

		// Preload recent documents so the tab is not empty on first open.
		$recentItems = $this->loadRecentItems();
		foreach ($recentItems as $item)
		{
			$dialog->addRecentItem($item);
		}
	}

	/**
	 * Ranking for "documents you touched lately", which is a different question from "when was the text
	 * last changed" — the date lists show. Here a rename, a move or an archive IS a touch worth ranking
	 * on, so the order takes the later of the two dates: since the freshness rework UPDATED_AT no longer
	 * moves on patch/materialize/compaction (only CONTENT_UPDATED_AT does), and CONTENT_UPDATED_AT alone
	 * would sink a document renamed a minute ago below one whose text was materialized last month.
	 * COALESCE keeps legacy rows (CONTENT_UPDATED_AT still NULL — no backfill) ranked by UPDATED_AT.
	 * Both GREATEST and COALESCE exist on MySQL 5.6 and PostgreSQL.
	 *
	 * The value never leaves the query: items carry a title and a collection name, no date, so this
	 * expression cannot leak into a displayed date.
	 */
	private function applyRecencyOrder(Query $query): void
	{
		$query
			->registerRuntimeField(
				new ExpressionField(
					'FRESHNESS',
					'GREATEST(%s, COALESCE(%s, %s))',
					['UPDATED_AT', 'CONTENT_UPDATED_AT', 'UPDATED_AT'],
				),
			)
			->addSelect('FRESHNESS')
			->setOrder(['FRESHNESS' => 'DESC', 'ID' => 'DESC']);
	}

	/**
	 * Returns up to RECENT_DISPLAY_LIMIT recent VIEW-accessible documents, ranked by the later of
	 * UPDATED_AT and CONTENT_UPDATED_AT (see applyRecencyOrder). Fetches RECENT_PREFETCH_LIMIT rows
	 * from DB and filters by access so the final list stays ≤20.
	 *
	 * @return Item[]
	 */
	private function loadRecentItems(): array
	{
		$query = DocumentTable::query()
			->setSelect(['ID', 'COLLECTION_ID', 'TITLE', 'COLLECTION.NAME'])
			->where('IS_ARCHIVED', 'N')
			->setLimit(self::RECENT_PREFETCH_LIMIT);
		$this->applyRecencyOrder($query);
		(new RecycleBinFilter())->applyExclusion($query);
		// Main documents are touched on every About-tab edit, so without this they would
		// dominate the recent list the dialog shows before the user types anything.
		(new MainDocumentFilter())->applyExclusion($query);

		$documents = $query->fetchCollection()->getAll();
		if (empty($documents))
		{
			return [];
		}

		if ($this->isAdmin())
		{
			$items = [];
			foreach ($documents as $document)
			{
				$items[] = $this->makeItem(
					(int)$document->getId(),
					(string)$document->getTitle(),
					(string)($document->getCollection()?->getName() ?? ''),
				);
				if (count($items) >= self::RECENT_DISPLAY_LIMIT)
				{
					break;
				}
			}

			return $items;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return [];
		}

		$docDescriptors = [];
		foreach ($documents as $document)
		{
			$docDescriptors[] = [
				'id' => (int)$document->getId(),
				'collectionId' => (int)$document->getCollectionId(),
			];
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = DocumentAccessService::batchGetEffectiveLevels($docDescriptors, $accessCodes, $userId);

		$items = [];
		foreach ($documents as $document)
		{
			$docId = (int)$document->getId();
			if (($levels[$docId] ?? 0) >= DocumentAccessService::LEVEL_VIEW)
			{
				$items[] = $this->makeItem(
					$docId,
					(string)$document->getTitle(),
					(string)($document->getCollection()?->getName() ?? ''),
				);
				if (count($items) >= self::RECENT_DISPLAY_LIMIT)
				{
					break;
				}
			}
		}

		return $items;
	}

	private function makeItem(int $id, string $title, string $collectionName = ''): Item
	{
		return new Item([
			'id' => $id,
			'entityId' => self::ENTITY_ID,
			'tabs' => [self::DOCUMENT_TAB_ID],
			'title' => $title,
			'supertitle' => Loc::getMessage('NOTE_MENTION_SELECTOR_CATEGORY_DOCUMENT'),
			'subtitle' => $collectionName,
			'avatarOptions' => [
				// DS tokens; both bgColor and iconColor are written as inline styles on elements
				// inside the popup, so they resolve against its design-system context (light/dark).
				'icon' => 'o-file',
				'iconColor' => 'var(--ui-color-design-filled-content-icon)',
				'bgColor' => 'var(--ui-color-design-filled-bg)',
			],
		]);
	}

	private function makeTab(): Tab
	{
		return new Tab([
			'id' => self::DOCUMENT_TAB_ID,
			'title' => Loc::getMessage('NOTE_ENTITY_SELECTOR_DOCUMENT_TAB_TITLE'),
			'icon' => ['default' => 'o-file'],
		]);
	}

	private function isAdmin(): bool
	{
		return PortalAdmin::isCurrentUserAdmin();
	}
}
