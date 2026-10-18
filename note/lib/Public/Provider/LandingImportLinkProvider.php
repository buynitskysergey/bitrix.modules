<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Provider;

use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Integration\Landing\PageReader;
use Bitrix\Note\Internal\Repository\ImportMapRepository;
use Bitrix\Note\Internal\Service\Import\Source\Landing\LandingId;

/**
 * Public facade for the legacy landing "База знаний 1.0" (TYPE='KNOWLEDGE' sites):
 * resolves a knowledge base / page that was converted into note back to the note
 * collection / document, so landing can redirect its old public entry points
 * (`/knowledge/<siteCode>/<pageCode>/`) to the new knowledge base.
 *
 * Callers pass what the old address holds - the site CODE and the CODE of its last segment -
 * and bringing that to the converted document is this facade's job, because the conversion
 * remembers what it converted by landing IDs: a document is mapped to the id of the page or of
 * the folder behind it ({@see LandingId}, TPL-01). So a code has to be resolved to a row first:
 *   - the id of the SITE comes out of the mapping of the base itself, whose anchor carries it,
 *     so translating the site code costs no query into landing at all;
 *   - the id of the ELEMENT comes from {@see PageReader}, which is asked for a page and for a
 *     section separately - the two live in tables of their own and are numbered independently,
 *     so which lookup answers is what tells the KIND of element an address named. Guessing it
 *     from the number would be impossible: one page and one section of a base share it freely.
 *     Both reads are indexed, by SITE_ID and CODE, and happen only on a hit of an old address,
 *     which is a redirect anyway;
 *   - a page that is the INDEX page of a section is the one exception to "a page has a document":
 *     it is converted as the body of that section, so its address resolves through the section
 *     ({@see resolvePageDocumentId}).
 * The anchor scheme stays encapsulated here and in LandingId, so what is looked up always
 * matches what the conversion wrote. The landing scope is opened and restored by the reader;
 * the provider mutates nothing.
 *
 * URL helpers return the note SPA paths (`/note/workspace|document/{id}/`).
 */
class LandingImportLinkProvider
{
	private const SOURCE_TYPE = 'landing';

	private ImportMapRepository $mapRepository;
	private PageReader $pageReader;

	public function __construct(?ImportMapRepository $mapRepository = null, ?PageReader $pageReader = null)
	{
		$this->mapRepository = $mapRepository ?? new ImportMapRepository();
		$this->pageReader = $pageReader ?? new PageReader();
	}

	/**
	 * Note collection id for a converted knowledge base, or null if it was never
	 * imported. $siteCode is the bare site CODE (slash-trimmed) or the numeric site
	 * id as a string when the site has no CODE - the same value LandingSource keyed on.
	 */
	public function resolveCollectionId(string $siteCode): ?int
	{
		$externalId = LandingId::collectionExternalId($siteCode);
		if ($externalId === '')
		{
			return null;
		}

		return $this->mapRepository->findCollectionId(self::SOURCE_TYPE, $externalId);
	}

	/**
	 * Note document id for a converted knowledge-base page or section, or null if it was not
	 * imported. $pageCode is the last segment of the old address - the CODE of a page (or its
	 * numeric id as a string when the page has no CODE), or the CODE of a section when the
	 * address points at one. The site CODE is required because page codes are unique only
	 * within a site.
	 *
	 * The address is resolved to the element it names and that element is looked up by its anchor.
	 * It cannot be looked up by the external key instead: the key of a page carries the code of
	 * its folder, which the address does not expose (landing resolves its last segment as a page
	 * and cannot tell a section apart), and the key of a section carries a `folder-` prefix,
	 * which no address carries either.
	 *
	 * A page is tried before a section, as landing itself resolves the last segment of an address:
	 * where a base holds both under one code, the page is what the address used to open - landing
	 * shows the index page of a folder for the address of the folder.
	 *
	 * An address that names several pages of the base - two same-named pages of different folders -
	 * resolves to one of them, the lowest id ({@see PageReader::findPageIdByCode}). That ambiguity
	 * is accepted (BRIEF decision 12); what matters is that the same address always leads to the
	 * same document.
	 */
	public function resolveDocumentId(string $siteCode, string $pageCode): ?int
	{
		$siteId = $this->resolveSiteId($siteCode);
		if ($siteId === null || $pageCode === '')
		{
			return null;
		}

		$pageId = $this->pageReader->findPageIdByCode($siteId, $pageCode);
		$documentId = $pageId !== null ? $this->resolvePageDocumentId($siteId, $pageId) : null;
		if ($documentId !== null)
		{
			return $documentId;
		}

		$folderId = $this->pageReader->findFolderIdByCode($siteId, $pageCode);

		return $folderId !== null
			? $this->mapRepository->findDocumentId(self::SOURCE_TYPE, LandingId::sectionAnchor($folderId))
			: null
		;
	}

	/**
	 * The document a converted PAGE lives in. As a rule that is the document of the page itself, but
	 * the index page of a section has none: its content is the body of the section document, so the
	 * conversion mapped the section and the address of the page has to arrive there
	 * ({@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource}).
	 *
	 * The section is asked for first and the page second, so that a base converted BEFORE this rule
	 * - where the index page did get a document of its own - still resolves: both mappings name the
	 * same content, and the section is the current one. The extra read costs a query only on a hit
	 * of an old address, which is a redirect anyway.
	 */
	private function resolvePageDocumentId(int $siteId, int $pageId): ?int
	{
		$sectionId = $this->pageReader->findSectionIdByIndexPageId($siteId, $pageId);
		$documentId = $sectionId !== null
			? $this->mapRepository->findDocumentId(self::SOURCE_TYPE, LandingId::sectionAnchor($sectionId))
			: null
		;

		return $documentId ?? $this->mapRepository->findDocumentId(
			self::SOURCE_TYPE,
			LandingId::pageAnchor($pageId),
		);
	}

	/**
	 * The id of the landing site behind a site CODE, taken from the mapping of the base: its key
	 * is that very CODE and its anchor is the id of the site. Reading it back keeps landing out
	 * of the translation entirely - and a code that no converted base answers for needs no
	 * landing query at all, which is the common case on a portal with one converted base.
	 */
	private function resolveSiteId(string $siteCode): ?int
	{
		$externalId = LandingId::collectionExternalId($siteCode);
		if ($externalId === '')
		{
			return null;
		}

		$mapping = $this->mapRepository->findCollectionMapping(self::SOURCE_TYPE, $externalId);

		return LandingId::siteIdFromCollectionAnchor($mapping['urlId'] ?? null);
	}

	/**
	 * Whether the current user may at least view the note collection. landing uses
	 * this to redirect only users who can actually open the target base: the landing
	 * KB access is transferred to the note collection on conversion
	 * ({@see \Bitrix\Note\Internal\Service\Import\CollectionAccessTransfer}), so the
	 * same audience that saw the KB is redirected, while anonymous / no-access
	 * visitors keep seeing the old page (landing KBs can be public - a blind redirect
	 * would push them into a note 403).
	 */
	public function currentUserCanView(int $collectionId): bool
	{
		return CollectionAccessService::currentUserHasLevel($collectionId, CollectionAccessService::LEVEL_VIEW);
	}

	public function getCollectionUrl(int $collectionId): string
	{
		return '/note/workspace/' . $collectionId . '/';
	}

	public function getDocumentUrl(int $documentId): string
	{
		return '/note/document/' . $documentId . '/';
	}
}
