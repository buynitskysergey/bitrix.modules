<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Source\Landing;

/**
 * Canonical id-scheme for the landing ("База знаний 1.0") import source (MAP-01).
 *
 * Every ImportMap key the landing flow produces must go through this class so the
 * two places that have to agree stay in sync:
 *   - {@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource} - builds the
 *     keys written into ImportMap during conversion;
 *   - {@see \Bitrix\Note\Public\Provider\LandingImportLinkProvider} - rebuilds the
 *     same keys to resolve a converted base/page back to its note collection/document
 *     so landing can redirect its old public entry points to the new knowledge base.
 *
 * Key and anchor forms (TPL-01):
 *   - collection key:  "<siteCode>"                         (b_landing_site.CODE, slash-trimmed; numeric ID fallback)
 *   - page key:        "<siteCode>:<folderCode>/<pageCode>" (b_landing.CODE; numeric ID fallback)
 *                      "<siteCode>:<pageCode>"              for a page outside any folder
 *   - folder key:      "<siteCode>:folder-<folderCode>"
 *   - collection anchor: "s<siteId>"                        (b_landing_site.ID)
 *   - page anchor:       "p<pageId>"                        (b_landing.ID)
 *   - section anchor:    "f<folderId>"                      (b_landing_folder.ID)
 *
 * External key and anchor have separate roles and must not be conflated. The key only has to
 * be unique inside the base - a landing page CODE is unique within its folder only, hence the
 * folder in the key. The anchor is what an old address and an in-content link are resolved by,
 * and it carries the ID of the source element rather than its address: an address made of codes
 * does not fit ImportMap.URL_ID and is ambiguous anyway (two same-named pages of different
 * folders share it), while an int always fits and names exactly one row.
 *
 * Bringing an old address to that ID is note's own job and needs landing's rows: the provider
 * resolves the last segment of the address through {@see \Bitrix\Note\Internal\Integration\Landing\PageReader},
 * and the source, which has already read the pages of the base, resolves in-content links from
 * what it holds in memory.
 *
 * The entity prefix is part of the anchor, not decoration: ImportMap lookups do not separate
 * bases from documents, so bare numbers would let a site ID match a page ID. A page and a
 * section need prefixes of their own for the same reason: landing keeps sections in a table
 * of their own (b_landing_folder) whose ids come from an independent sequence, so one page and
 * one section of the same base routinely carry the same number.
 *
 * Knowledge-base site CODE is stored slash-wrapped (`/american-football/`) while
 * public URLs and in-content links use the bare form (`american-football`); the keys
 * always use the bare form, so {@see normalizeSiteCode} is the single normalisation point.
 *
 * Every key here is made of codes landing lets grow far past the column it is written into, so a
 * key is cut to fit ({@see fitExternalIdColumn}). A key that already fits is left exactly as it
 * is - keys of bases converted earlier have to keep resolving.
 */
final class LandingId
{
	public const FOLDER_EXTERNAL_ID_PREFIX = 'folder-';
	public const COLLECTION_ANCHOR_PREFIX = 's';
	public const PAGE_ANCHOR_PREFIX = 'p';
	public const SECTION_ANCHOR_PREFIX = 'f';

	/**
	 * Size of b_note_import_map.EXTERNAL_ID (install/migrations/tables.php), in characters -
	 * `varchar(128)` bounds characters on both MySQL and PostgreSQL.
	 *
	 * A key has to be brought to it here because nothing downstream does: the field is declared
	 * without a size ({@see \Bitrix\Note\Internal\Model\ImportMapTable}), so the client-side cut
	 * of {@see \Bitrix\Main\DB\SqlHelper::convertToDb} does not apply, and the write itself is raw
	 * SQL ({@see \Bitrix\Note\Internal\Repository\ImportMapRepository}). What is left is the
	 * database: an error mid-conversion on a strict portal, a silent cut on a lenient one.
	 */
	public const EXTERNAL_ID_MAX_LENGTH = 128;

	private const EXTERNAL_ID_HASH_LENGTH = 12;
	private const EXTERNAL_ID_HASH_SEPARATOR = '~';

	public static function normalizeSiteCode(string $siteCode): string
	{
		return trim($siteCode, '/');
	}

	/**
	 * The key of a base: its bare site CODE, cut to the column like the keys below.
	 *
	 * The cut form belongs to the map and to nothing else. What the screen and the steps carry for a
	 * base is the CODE whole, because a site is found by it and by nothing else
	 * ({@see \Bitrix\Note\Internal\Integration\Landing\SiteReader::findSiteIdByExternalId}); a cut
	 * form in that role would name a base the source cannot find. The two roles are told apart by
	 * {@see \Bitrix\Note\Internal\Service\Import\Step\CreateCollectionStep::collectionMapKey}, which
	 * every reader and writer of the map goes through - this method is the other end of it, the one
	 * {@see \Bitrix\Note\Public\Provider\LandingImportLinkProvider} resolves an old address by.
	 */
	public static function collectionExternalId(string $siteCode): string
	{
		return self::fitExternalIdColumn(self::normalizeSiteCode($siteCode));
	}

	/**
	 * A landing page CODE is unique only inside its folder, so the folder belongs to the key:
	 * without it two same-named pages of different folders share one key and the mapping of
	 * the first is overwritten by the second in ImportMapRepository::upsert().
	 */
	public static function pageExternalId(string $siteCode, string $folderCode, string $pageCode): string
	{
		$siteCode = self::normalizeSiteCode($siteCode);
		if ($siteCode === '' || $pageCode === '')
		{
			return '';
		}

		if ($folderCode === '')
		{
			return self::fitExternalIdColumn($siteCode . ':' . $pageCode);
		}

		return self::fitExternalIdColumn($siteCode . ':' . $folderCode . '/' . $pageCode);
	}

	public static function folderExternalId(string $siteCode, string $folderCode): string
	{
		$siteCode = self::normalizeSiteCode($siteCode);

		return self::fitExternalIdColumn($siteCode . ':' . self::FOLDER_EXTERNAL_ID_PREFIX . $folderCode);
	}

	/**
	 * A key too long for the column keeps a readable head and carries a hash of the whole key.
	 * The hash is what keeps two long keys apart: pages of one folder differ only in their own
	 * code, which is exactly the end a plain cut drops, so a head alone would hand one mapping to
	 * several pages - the collision the folder in the key exists to prevent, back through the
	 * other end. A key that fits is returned untouched, down to the character.
	 *
	 * Counted and cut in characters rather than bytes: characters are what `varchar(128)` bounds,
	 * and a byte-wise cut can end mid-character - a broken sequence the database rejects outright
	 * instead of storing a shorter key. A Cyrillic code is where the two part ways.
	 */
	private static function fitExternalIdColumn(string $externalId): string
	{
		if (mb_strlen($externalId) <= self::EXTERNAL_ID_MAX_LENGTH)
		{
			return $externalId;
		}

		$headLength = self::EXTERNAL_ID_MAX_LENGTH - self::EXTERNAL_ID_HASH_LENGTH - 1;

		return mb_substr($externalId, 0, $headLength)
			. self::EXTERNAL_ID_HASH_SEPARATOR
			. substr(md5($externalId), 0, self::EXTERNAL_ID_HASH_LENGTH)
		;
	}

	/**
	 * Anchor of a converted base (ImportMap.URL_ID): the id of the landing site it was
	 * converted from. Also how the id of a base is recovered from its own mapping row
	 * ({@see siteIdFromCollectionAnchor}), which saves a query into landing.
	 */
	public static function collectionAnchor(int $siteId): string
	{
		return $siteId > 0 ? self::COLLECTION_ANCHOR_PREFIX . $siteId : '';
	}

	/**
	 * Anchor of a converted page: the id of the b_landing row it was converted from.
	 */
	public static function pageAnchor(int $pageId): string
	{
		return $pageId > 0 ? self::PAGE_ANCHOR_PREFIX . $pageId : '';
	}

	/**
	 * Anchor of a converted section: the id of the folder it was converted from - a row of
	 * b_landing_folder, or of b_landing (`FOLDER='Y'`) on a base still on landing's old folder
	 * model ({@see \Bitrix\Note\Internal\Integration\Landing\PageReader::listFoldersForSite}).
	 * The two models number their rows independently of pages, so the section space needs a
	 * prefix of its own: with one prefix for both, a section and a page of the same number
	 * would share an anchor and an old address could land in a foreign document.
	 *
	 * Known limitation, deliberately not worked around: a base converted BEFORE landing moved
	 * its folders keeps the old row ids in its section anchors, and after the move landing hands
	 * out new ones, so nothing matches and the old address of a section leads to the root of the
	 * base instead of the section. The degradation is soft - never into a foreign document - and
	 * converting the base again writes the current ids.
	 *
	 * The `folder-` prefix of the section KEY never leaks in here: key and anchor have separate
	 * roles (see the class docblock).
	 */
	public static function sectionAnchor(int $folderId): string
	{
		return $folderId > 0 ? self::SECTION_ANCHOR_PREFIX . $folderId : '';
	}

	/**
	 * The site id back out of a collection anchor, so that the id of the base can be read from
	 * the mapping the conversion itself wrote ({@see \Bitrix\Note\Public\Provider\LandingImportLinkProvider}).
	 * This is the only place that takes an anchor apart, so the form is written and read here.
	 *
	 * null for anything that is not an anchor of this form - a base converted by an earlier
	 * version of the import carries whatever that version wrote, and such a base must degrade
	 * to "the address resolves to no element" rather than to a wrong element.
	 */
	public static function siteIdFromCollectionAnchor(?string $anchor): ?int
	{
		if ($anchor === null || !preg_match('/^' . self::COLLECTION_ANCHOR_PREFIX . '([0-9]+)$/D', $anchor, $matches))
		{
			return null;
		}

		$siteId = (int)$matches[1];

		return $siteId > 0 ? $siteId : null;
	}
}
