<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Landing;

use Bitrix\Landing\Internals\LandingTable;
use Bitrix\Landing\Internals\SiteTable;
use Bitrix\Landing\Rights;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

/**
 * Read-only proxy over landing's Site/Knowledge entities.
 * Single place where note touches \Bitrix\Landing\* for site-level reads.
 *
 * Everything here that answers about the rights of a user answers about the CURRENT user or about
 * nobody: landing resolves rights for whoever is logged in and takes no user of its own
 * ({@see Rights::hasAccessForSite} - "can current user do something", with a per-process cache keyed
 * by site alone). So a read that names a user is honest only while that user IS the current one, and
 * outside such a request it refuses to answer instead of answering about somebody else (ALG-03,
 * {@see isCurrentUser}). A background step therefore gets an empty answer, never the rights of the
 * person whose request happens to be running the agent.
 */
class SiteReader
{
	private const KNOWLEDGE_TYPE = 'KNOWLEDGE';

	/**
	 * Returns knowledge-base sites the user has at least read access to. Answers only for the user
	 * running the request, and with an empty list for anyone else - see the note of this class.
	 *
	 * @param int $userId
	 * @return array<int, array{ID:int, CODE:?string, TITLE:string, pageCount:int}>
	 */
	public function listKnowledgeBaseSitesForUser(int $userId): array
	{
		if (!self::isCurrentUser($userId) || !Loader::includeModule('landing'))
		{
			return [];
		}

		// The whole flow - site rows + per-row access check + page counts -
		// must run inside the KNOWLEDGE scope. Rights::hasAccessForSite consults
		// Role::setExpectedType(...), which Knowledge::init() configures on setScope.
		// Calling hasAccessForSite outside the scope yields false for every KB site.
		return LandingScope::runInKnowledgeScope(function () use ($userId): array {
			$rows = SiteTable::getList([
				'select' => ['ID', 'CODE', 'TITLE', 'LANDING_ID_INDEX'],
				'filter' => [
					'=TYPE' => self::KNOWLEDGE_TYPE,
					'=DELETED' => 'N',
					'CHECK_PERMISSIONS' => 'N',
				],
				'order' => ['TITLE' => 'ASC'],
			])->fetchAll();

			if (empty($rows))
			{
				return [];
			}

			$siteIds = array_map(static fn(array $r): int => (int)$r['ID'], $rows);
			// The home page of a base (LANDING_ID_INDEX) is SYS='Y', and the conversion reads it like
			// any other page, so it belongs in the count. What comes out is how many PAGES a base has
			// and not how many documents converting it creates - {@see countPagesForSitesScoped}.
			$indexPageIds = [];
			foreach ($rows as $row)
			{
				$indexPageId = (int)($row['LANDING_ID_INDEX'] ?? 0);
				if ($indexPageId > 0)
				{
					$indexPageIds[] = $indexPageId;
				}
			}
			$pageCounts = $this->countPagesForSitesScoped($siteIds, $indexPageIds);

			$accessible = [];
			foreach ($rows as $row)
			{
				$siteId = (int)$row['ID'];
				if (!$this->currentUserHasReadAccessScoped($siteId))
				{
					continue;
				}

				$accessible[$siteId] = [
					'ID' => $siteId,
					'CODE' => $row['CODE'] !== null ? (string)$row['CODE'] : null,
					'TITLE' => (string)($row['TITLE'] ?? ''),
					'pageCount' => $pageCounts[$siteId] ?? 0,
				];
			}

			return array_values($accessible);
		});
	}

	/**
	 * LANDING_ID_INDEX is the site's home (index) page id - used to surface the
	 * main page first during conversion. 0 when the site has no index page set.
	 *
	 * @return array{ID:int, CODE:?string, TITLE:string, LANDING_ID_INDEX:int}|null
	 */
	public function getSiteById(int $siteId): ?array
	{
		if ($siteId <= 0 || !Loader::includeModule('landing'))
		{
			return null;
		}

		$row = LandingScope::runInKnowledgeScope(static fn() => SiteTable::getList([
			'select' => ['ID', 'CODE', 'TITLE', 'LANDING_ID_INDEX'],
			'filter' => [
				'=ID' => $siteId,
				'=TYPE' => self::KNOWLEDGE_TYPE,
				'=DELETED' => 'N',
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
			'LANDING_ID_INDEX' => (int)($row['LANDING_ID_INDEX'] ?? 0),
		];
	}

	public function findSiteIdByCode(string $code): ?int
	{
		$bare = trim($code, '/');
		if ($bare === '' || !Loader::includeModule('landing'))
		{
			return null;
		}

		// KB site CODE is stored slash-wrapped (`/code/`); callers pass the bare form
		// (see LandingSource::siteCode). Match both so either shape resolves.
		$candidates = array_values(array_unique([$bare, '/' . $bare . '/']));

		$row = LandingScope::runInKnowledgeScope(static fn() => SiteTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=CODE' => $candidates,
				'=TYPE' => self::KNOWLEDGE_TYPE,
				'=DELETED' => 'N',
				'CHECK_PERMISSIONS' => 'N',
			],
			'limit' => 1,
		])->fetch());

		return $row !== false ? (int)$row['ID'] : null;
	}

	/**
	 * The site behind the id a base is named by outside landing: its CODE, or the site id as a string
	 * for a base that has no code of its own - the two forms an external id of a landing base is built
	 * from ({@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource}). Null when neither names
	 * a knowledge base that is still there, so a caller asking about a base by name gets an answer
	 * about one row or about none.
	 */
	public function findSiteIdByExternalId(string $externalId): ?int
	{
		if (self::isDigits($externalId))
		{
			return $this->getSiteById((int)$externalId) !== null ? (int)$externalId : null;
		}

		return $this->findSiteIdByCode($externalId);
	}

	/**
	 * `ctype_digit()` equivalent: PHP on the cloud infrastructure is built without the `ctype`
	 * extension, so any call to it is a fatal error there. The `D` modifier keeps the semantics
	 * exactly - a trailing newline does not pass - and an empty string stays false.
	 */
	private static function isDigits(string $value): bool
	{
		return preg_match('/^[0-9]+$/D', $value) === 1;
	}

	/**
	 * Whether $userId may read the site - answered for real, or not at all: outside a request of
	 * that user the method returns false rather than the answer landing has for somebody else.
	 *
	 * The parameter used to be informational, and that was the defect ([P6.T9]): the signature
	 * promised a check of the user named in it while landing checked whoever was logged in, so the
	 * same call meant one thing in a request and another in a background job. It now means one thing
	 * everywhere, and the direction of the difference is narrowing - a caller that cannot be answered
	 * is refused, never granted.
	 */
	public function userHasReadAccess(int $siteId, int $userId): bool
	{
		if ($siteId <= 0 || !self::isCurrentUser($userId) || !Loader::includeModule('landing'))
		{
			return false;
		}

		return LandingScope::runInKnowledgeScope(
			fn(): bool => $this->currentUserHasReadAccessScoped($siteId),
		);
	}

	/**
	 * Caller MUST already be inside LandingScope::runInKnowledgeScope() and MUST have established
	 * that the current user is the one it means ({@see isCurrentUser}).
	 */
	private function currentUserHasReadAccessScoped(int $siteId): bool
	{
		if ($siteId <= 0)
		{
			return false;
		}

		return Rights::hasAccessForSite($siteId, Rights::ACCESS_TYPES['read']);
	}

	/**
	 * Whether the rights landing resolves right now are the rights of $userId. False for an
	 * anonymous process - an agent has no user of its own, so it is nobody in particular.
	 */
	private static function isCurrentUser(int $userId): bool
	{
		return $userId > 0 && (int)(CurrentUser::get()->getId() ?? 0) === $userId;
	}

	/**
	 * Caller MUST already be inside LandingScope::runInKnowledgeScope().
	 *
	 * @param int[] $siteIds
	 * @param int[] $indexPageIds home-page ids (SYS='Y') to count alongside SYS='N' pages
	 * @return array<int, int> site id => how many pages the base has
	 */
	private function countPagesForSitesScoped(array $siteIds, array $indexPageIds = []): array
	{
		$counts = [];
		foreach ($siteIds as $id)
		{
			$counts[(int)$id] = 0;
		}

		// SYS='N' content pages plus each site's SYS='Y' home page (opted in by id): how many pages a
		// base has, which is not how many documents converting it creates. A section is a document and
		// no page at all, so it is not counted here; the index page of a section is a page and becomes
		// no document of its own - it is the body of that section - and the home page becomes the
		// description of the base rather than a document ([P3.T19],
		// {@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource::getDocumentsPage}). Telling
		// the two numbers apart takes the folder structure of every base of the list, which the action
		// that draws that list does not read.
		$pageFilter = empty($indexPageIds)
			? ['=SYS' => 'N']
			: [
				'LOGIC' => 'OR',
				['=SYS' => 'N'],
				['=ID' => array_values($indexPageIds)],
			];

		$rows = LandingTable::getList([
			'select' => ['SITE_ID', 'CNT'],
			'filter' => [
				'=SITE_ID' => $siteIds,
				'=DELETED' => 'N',
				'=FOLDER' => 'N',
				$pageFilter,
				'CHECK_PERMISSIONS' => 'N',
			],
			'runtime' => [
				new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)'),
			],
			'group' => ['SITE_ID'],
		])->fetchAll();

		foreach ($rows as $row)
		{
			$counts[(int)$row['SITE_ID']] = (int)$row['CNT'];
		}

		return $counts;
	}
}
