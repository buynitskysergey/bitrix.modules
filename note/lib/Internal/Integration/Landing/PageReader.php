<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Landing;

use Bitrix\Landing\Internals\FolderTable;
use Bitrix\Landing\Internals\LandingTable;
use Bitrix\Main\Loader;

/**
 * Read-only proxy over landing's pages (b_landing) and folders (b_landing_folder).
 */
class PageReader
{
	/**
	 * The widest window one paginated read answers with ({@see listPageIdsForSite}).
	 *
	 * The conversion narrows its own window to this number instead of repeating it
	 * ({@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource::getDocumentsPage}): asked for
	 * a window wider than this, the read hands out a short batch, and a short batch is how the
	 * conversion tells the end of a base.
	 */
	public const MAX_PAGE_LIMIT = 100;

	/**
	 * Non-system-page filter fragment with the site's index (home) page opted back in.
	 *
	 * Content pages are SYS='N', but a knowledge base's home page
	 * (b_landing_site.LANDING_ID_INDEX) is always a SYS='Y' landing page. It carries the
	 * KB's primary navigation - link lists whose in-body `#landing<id>` hrefs are the
	 * cross-page links the import rewrites - so it must be enumerated like any other
	 * document. Every OTHER system page (header/footer/sidebar/search) stays excluded.
	 * When no index id is supplied the plain SYS='N' filter is used.
	 *
	 * @return array ORM filter sub-expression (AND'd into the caller's filter)
	 */
	private static function systemPageFilter(?int $indexPageId): array
	{
		if ($indexPageId === null || $indexPageId <= 0)
		{
			return ['=SYS' => 'N'];
		}

		return [
			'LOGIC' => 'OR',
			['=SYS' => 'N'],
			['=ID' => $indexPageId],
		];
	}

	/**
	 * Filter of the two reads that enumerate the document pages of a base - the whole listing and the
	 * paginated ids. One builder, because a page one read hands out and the other skips breaks the
	 * conversion: the tree of the base is built from the listing, while the content step walks the
	 * same pages by offset until a batch comes back short.
	 *
	 * $excludePageIds drops pages that are converted as something other than a document of their
	 * own: the index page of a section becomes the body of the section document, so a document of
	 * its own would duplicate it ({@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource}).
	 *
	 * @param int[] $excludePageIds
	 */
	private static function documentPageFilter(int $siteId, ?int $indexPageId, array $excludePageIds = []): array
	{
		$filter = [
			'=SITE_ID' => $siteId,
			'=DELETED' => 'N',
			'=FOLDER' => 'N',
			self::systemPageFilter($indexPageId),
			// Import is a trusted pipeline: read pages independently of the caller's
			// landing rights, mirroring SiteReader::countPagesForSitesScoped. Without
			// this, a knowledge-scope call from a context lacking landing permissions
			// (e.g. the background stepper) returns EMPTY even though the pages exist.
			'CHECK_PERMISSIONS' => 'N',
		];

		$excluded = self::positiveIds($excludePageIds);
		if ($excluded !== [])
		{
			$filter['!@ID'] = $excluded;
		}

		return $filter;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function positiveIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));
	}

	/**
	 * Returns non-system document pages of a knowledge-base site, plus the SYS='Y' home
	 * page when $indexPageId is supplied (see {@see documentPageFilter}).
	 *
	 * Landing serves knowledge bases from the *draft* copy (DRAFT_MODE='Y' in
	 * intranet/.../knowledge/index.php), so ACTIVE/PUBLIC are intentionally NOT
	 * filtered here - a KB page is visible to readers regardless of publication
	 * state, gated only by rights. SYS='N' filters out 404/503 system pages,
	 * FOLDER='N' returns leaf documents only (folders are exposed via
	 * {@see listFoldersForSite}).
	 *
	 * @return array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, SORT:int}>
	 */
	public function listForSite(int $siteId, ?int $indexPageId = null): array
	{
		if ($siteId <= 0 || !Loader::includeModule('landing'))
		{
			return [];
		}

		$rows = LandingScope::runInKnowledgeScope(static fn(): array => LandingTable::getList([
			'select' => ['ID', 'CODE', 'TITLE', 'FOLDER_ID'],
			'filter' => self::documentPageFilter($siteId, $indexPageId),
			'order' => ['ID' => 'ASC'],
		])->fetchAll());

		$pages = [];
		foreach ($rows as $i => $row)
		{
			$pages[] = [
				'ID' => (int)$row['ID'],
				'CODE' => $row['CODE'] !== null ? (string)$row['CODE'] : null,
				'TITLE' => (string)($row['TITLE'] ?? ''),
				'FOLDER_ID' => $row['FOLDER_ID'] !== null ? (int)$row['FOLDER_ID'] : null,
				'SORT' => $i,
			];
		}

		return $pages;
	}

	/**
	 * Returns the sections of a site - used as tree nodes - in one shape whichever folder model
	 * landing holds them in: the parent always arrives as `FOLDER_ID`, which is the field the
	 * pages of {@see listForSite} point at their section with.
	 *
	 * Landing keeps folders in b_landing_folder (parent in `PARENT_ID`) and moves them there from
	 * b_landing (`FOLDER='Y'`, parent in `FOLDER_ID`) by a stepper that also runs on demand for a
	 * single site, opening one included ({@see \Bitrix\Landing\Update\Landing\FolderNew}). A live
	 * portal is therefore almost certainly on the new model, while a portal that never opened this
	 * base can still be on the old one - hence both, new first.
	 *
	 * The old model answers only for a site with no folder in the new one at all: the migration
	 * moves every folder of a site in one pass, so a single row there means this site is converted.
	 *
	 * `INDEX_ID` is the page landing shows at the address of the folder, and it is part of the
	 * answer in both models - see {@see listFoldersFromLandingTable} for what plays that part in
	 * the old one.
	 *
	 * @return array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, INDEX_ID:?int}>
	 */
	public function listFoldersForSite(int $siteId): array
	{
		if ($siteId <= 0 || !Loader::includeModule('landing'))
		{
			return [];
		}

		$folders = $this->listFoldersFromFolderTable($siteId);

		return $folders !== [] ? $folders : $this->listFoldersFromLandingTable($siteId);
	}

	/**
	 * Sections out of landing's current folder model (b_landing_folder).
	 *
	 * `FolderTable` is a plain DataManager: it applies no rights of its own and takes no
	 * `CHECK_PERMISSIONS` key (the key would leak into the ORM and blow up), so this read is
	 * permission-independent like the page reads it accompanies. ACTIVE is not filtered and
	 * DELETED is, for the same reason as in {@see listForSite}: a knowledge base is served from
	 * its draft copy. The site type lives on the site row rather than on a folder, so the rows
	 * are constrained by SITE_ID alone and need no landing scope.
	 *
	 * @return array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, INDEX_ID:?int}>
	 */
	private function listFoldersFromFolderTable(int $siteId): array
	{
		$rows = FolderTable::getList([
			'select' => ['ID', 'CODE', 'TITLE', 'PARENT_ID', 'INDEX_ID'],
			'filter' => [
				'=SITE_ID' => $siteId,
				'=DELETED' => 'N',
			],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();

		return self::folderNodes($rows, 'PARENT_ID', 'INDEX_ID');
	}

	/**
	 * Sections out of landing's old folder model: rows of b_landing itself with `FOLDER='Y'`.
	 *
	 * The old model has no index column, because the folder row IS the index page of the folder:
	 * it holds blocks of its own and landing serves them at the address of the folder. That is
	 * landing's own rule, stated by its migration - {@see \Bitrix\Landing\Update\Landing\FolderNew}
	 * turns the row into an ordinary page and registers it as `INDEX_ID` of the new folder - so the
	 * row answers as its own index page here and needs no guesswork over codes.
	 *
	 * @return array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, INDEX_ID:?int}>
	 */
	private function listFoldersFromLandingTable(int $siteId): array
	{
		$rows = LandingScope::runInKnowledgeScope(static fn(): array => LandingTable::getList([
			'select' => ['ID', 'CODE', 'TITLE', 'FOLDER_ID'],
			'filter' => [
				'=SITE_ID' => $siteId,
				'=DELETED' => 'N',
				'=FOLDER' => 'Y',
				// Keep the folder tree consistent with the (permission-independent) page
				// reads - see listForSite.
				'CHECK_PERMISSIONS' => 'N',
			],
			'order' => ['ID' => 'ASC'],
		])->fetchAll());

		return self::folderNodes($rows, 'FOLDER_ID', null);
	}

	/**
	 * Brings folder rows of either model to the one shape the tree is built from: the parent under
	 * `FOLDER_ID` and the index page under `INDEX_ID`, whatever the model calls them.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param string $parentField parent column of the model these rows came from
	 * @param string|null $indexField index-page column, null when the row is its own index page
	 * @return array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, INDEX_ID:?int}>
	 */
	private static function folderNodes(array $rows, string $parentField, ?string $indexField): array
	{
		$folders = [];
		foreach ($rows as $row)
		{
			$folderId = (int)$row['ID'];
			$parentId = (int)($row[$parentField] ?? 0);
			$indexPageId = $indexField !== null ? (int)($row[$indexField] ?? 0) : $folderId;
			$folders[] = [
				'ID' => $folderId,
				'CODE' => $row['CODE'] !== null ? (string)$row['CODE'] : null,
				'TITLE' => (string)($row['TITLE'] ?? ''),
				'FOLDER_ID' => $parentId > 0 ? $parentId : null,
				'INDEX_ID' => $indexPageId > 0 ? $indexPageId : null,
			];
		}

		return $folders;
	}

	/**
	 * Returns page IDs paginated by ID ASC for a site (used by getDocumentsPage).
	 *
	 * @param int[] $excludePageIds pages converted as something else - {@see documentPageFilter}
	 * @return array<int, int>
	 */
	public function listPageIdsForSite(
		int $siteId,
		int $offset,
		int $limit,
		?int $indexPageId = null,
		array $excludePageIds = [],
	): array
	{
		if ($siteId <= 0 || $limit <= 0 || !Loader::includeModule('landing'))
		{
			return [];
		}

		$pageOffset = max(0, $offset);
		$pageLimit = min($limit, self::MAX_PAGE_LIMIT);
		$rows = LandingScope::runInKnowledgeScope(static fn(): array => LandingTable::getList([
			'select' => ['ID'],
			'filter' => self::documentPageFilter($siteId, $indexPageId, $excludePageIds),
			'order' => ['ID' => 'ASC'],
			'offset' => $pageOffset,
			'limit' => $pageLimit,
		])->fetchAll());

		return array_map(static fn(array $r): int => (int)$r['ID'], $rows);
	}

	/**
	 * Fetches a single page row (used to resolve titles for sample reports etc.).
	 *
	 * @return array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, SITE_ID:int}|null
	 */
	public function getById(int $pageId): ?array
	{
		if ($pageId <= 0 || !Loader::includeModule('landing'))
		{
			return null;
		}

		$row = LandingScope::runInKnowledgeScope(static fn() => LandingTable::getList([
			'select' => ['ID', 'CODE', 'TITLE', 'FOLDER_ID', 'SITE_ID'],
			'filter' => [
				'=ID' => $pageId,
				// Trusted import read - see listForSite.
				'CHECK_PERMISSIONS' => 'N',
			],
			'limit' => 1,
		])->fetch());

		if ($row === false)
		{
			return null;
		}

		return [
			'ID' => (int)$row['ID'],
			'CODE' => $row['CODE'] !== null ? (string)$row['CODE'] : null,
			'TITLE' => (string)($row['TITLE'] ?? ''),
			'FOLDER_ID' => $row['FOLDER_ID'] !== null ? (int)$row['FOLDER_ID'] : null,
			'SITE_ID' => (int)$row['SITE_ID'],
		];
	}

	/**
	 * The id of the PAGE a public address of a base names by its last segment (its CODE), or null
	 * when the base has no such page. A section of the same code is a different element with an
	 * anchor of its own, so it is asked for separately ({@see findFolderIdByCode}) - which is what
	 * tells the caller WHICH kind an address resolved to, instead of guessing it from a number.
	 *
	 * An old address carries no folder - landing resolves its last segment inside whatever folder
	 * the preceding segments named - so several pages of one base can match: two same-named pages
	 * of different folders. The lowest id wins, which keeps the answer the same on every request
	 * (the ambiguity itself is accepted, BRIEF decision 12) instead of leaving it to row order.
	 *
	 * SYS pages are NOT excluded here, unlike in the reads that enumerate the import: the home
	 * page of a base is always SYS='Y', and its old address has to keep working - the conversion
	 * maps it onto the description of the base. Every other system page (404/503) simply has no
	 * mapping, so it resolves to nothing anyway. DELETED='N' and no publication filter, as in
	 * {@see listForSite}: landing serves a knowledge base from its draft copy.
	 */
	public function findPageIdByCode(int $siteId, string $code): ?int
	{
		if ($siteId <= 0 || $code === '' || !Loader::includeModule('landing'))
		{
			return null;
		}

		$codes = self::codeVariants($code);

		$row = LandingScope::runInKnowledgeScope(static fn() => LandingTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=SITE_ID' => $siteId,
				'=CODE' => $codes,
				'=DELETED' => 'N',
				'=FOLDER' => 'N',
				// Resolving an old address must not depend on the landing rights of whoever
				// follows it: the redirect is gated by the note collection instead
				// ({@see \Bitrix\Note\Public\Provider\LandingImportLinkProvider}), and a reader
				// of a converted base may well have lost the rights on the landing one.
				'CHECK_PERMISSIONS' => 'N',
			],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch());

		return $row !== false ? (int)$row['ID'] : null;
	}

	/**
	 * The id of the SECTION a code names inside a base, or null when the base has none - the
	 * folder-model counterpart of {@see findPageIdByCode}, so a caller learns the kind of the
	 * element from which lookup answered.
	 *
	 * Both folder models are asked, current one first, exactly as {@see listFoldersForSite}
	 * enumerates them; the id that comes back therefore belongs to the same space the conversion
	 * of this base wrote its section anchors in.
	 */
	public function findFolderIdByCode(int $siteId, string $code): ?int
	{
		if ($siteId <= 0 || $code === '' || !Loader::includeModule('landing'))
		{
			return null;
		}

		$codes = self::codeVariants($code);

		$row = FolderTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=SITE_ID' => $siteId,
				'=CODE' => $codes,
				'=DELETED' => 'N',
			],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();
		if ($row !== false)
		{
			return (int)$row['ID'];
		}

		$row = LandingScope::runInKnowledgeScope(static fn() => LandingTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=SITE_ID' => $siteId,
				'=CODE' => $codes,
				'=DELETED' => 'N',
				'=FOLDER' => 'Y',
				// Trusted redirect read - see findPageIdByCode.
				'CHECK_PERMISSIONS' => 'N',
			],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch());

		return $row !== false ? (int)$row['ID'] : null;
	}

	/**
	 * The id of the section whose index page this page is, or null when it indexes none.
	 *
	 * The index page of a section is converted into no document of its own: its content becomes the
	 * body of the section document ({@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource}),
	 * so the old address of that page has to lead to the section - this is what says it does.
	 *
	 * Only the current folder model is asked. In the old one a folder row is its own index page
	 * ({@see listFoldersFromLandingTable}), and it never reaches this lookup: an address resolves to
	 * a page through {@see findPageIdByCode}, which returns leaf pages only (`FOLDER='N'`), while
	 * the folder row itself is answered by {@see findFolderIdByCode} as the section it is.
	 */
	public function findSectionIdByIndexPageId(int $siteId, int $pageId): ?int
	{
		if ($siteId <= 0 || $pageId <= 0 || !Loader::includeModule('landing'))
		{
			return null;
		}

		$row = FolderTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=SITE_ID' => $siteId,
				'=INDEX_ID' => $pageId,
				'=DELETED' => 'N',
			],
			'order' => ['ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return $row !== false ? (int)$row['ID'] : null;
	}

	/**
	 * Both cases are asked for: landing itself compares codes case-insensitively, but only a
	 * MySQL collation does that on its own - on PostgreSQL the stored case has to be matched.
	 *
	 * @return string[]
	 */
	private static function codeVariants(string $code): array
	{
		return array_values(array_unique([$code, mb_strtolower($code)]));
	}

	/**
	 * Batch form of {@see getById}: a set of b_landing ids resolved in a single query, keyed by id.
	 *
	 * Two passes of the conversion read rows this way. The in-body link pass rewrites landing
	 * `#landing<id>` links into canonical /knowledge/<siteCode>/<pageCode>/ URLs, for which the CODE
	 * and the SITE_ID of the row are enough; the content pass builds the documents of a whole page
	 * batch, which also needs the TITLE and the FOLDER_ID of every page (see LandingSource).
	 *
	 * `IS_FOLDER` is part of the answer because a base still on landing's old folder model keeps
	 * its sections in this very table, and a link may bind to one: the two kinds of element are
	 * converted under anchors of their own ({@see \Bitrix\Note\Internal\Service\Import\Source\Landing\LandingId}).
	 *
	 * @param int[] $ids
	 * @return array<int, array{ID:int, CODE:?string, TITLE:string, FOLDER_ID:?int, SITE_ID:int, IS_FOLDER:bool}>
	 */
	public function getByIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $v): bool => $v > 0,
		)));
		if (empty($ids) || !Loader::includeModule('landing'))
		{
			return [];
		}

		$rows = LandingScope::runInKnowledgeScope(static fn(): array => LandingTable::getList([
			'select' => ['ID', 'CODE', 'TITLE', 'FOLDER_ID', 'SITE_ID', 'FOLDER'],
			'filter' => [
				'=ID' => $ids,
				// Trusted import read - see listForSite. Resolving in-body `#landing<id>` links
				// must not depend on the caller's landing rights either, or the import (running
				// under the stepper) leaves every internal link unresolved.
				'CHECK_PERMISSIONS' => 'N',
			],
		])->fetchAll());

		$result = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$result[$id] = [
				'ID' => $id,
				'CODE' => $row['CODE'] !== null ? (string)$row['CODE'] : null,
				'TITLE' => (string)($row['TITLE'] ?? ''),
				'FOLDER_ID' => $row['FOLDER_ID'] !== null ? (int)$row['FOLDER_ID'] : null,
				'SITE_ID' => (int)$row['SITE_ID'],
				'IS_FOLDER' => ($row['FOLDER'] ?? 'N') === 'Y',
			];
		}

		return $result;
	}
}
