<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Landing;

use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Internals\LandingTable;
use Bitrix\Landing\Internals\TemplateRefTable;
use Bitrix\Main\Loader;

/**
 * Read-only proxy that extracts navigation menus from a knowledge-base site.
 *
 * Landing stores menus inside "area" landings (site-level header/footer templates
 * registered in b_landing_template_ref). Inside those landings, menu blocks render
 * `<a href="#landing<id>">title</a>` anchors that point to other landings of the
 * same site. We scan every area landing's published block CONTENT and resolve
 * each `#landing<id>` href back to a {pageId, title} pair via b_landing.
 *
 * Output ordering follows block SORT, then DOM order inside the block.
 */
final class MenuReader
{
	/**
	 * @return array<int, array{title:string, pageId:int, href:string, depth:int}>
	 */
	public function getMenuForSite(int $siteId): array
	{
		if ($siteId <= 0 || !Loader::includeModule('landing'))
		{
			return [];
		}

		$areaLandingIds = $this->loadAreaLandingIds($siteId);
		if (empty($areaLandingIds))
		{
			return [];
		}

		$blockContents = $this->loadBlockContents($areaLandingIds);
		if (empty($blockContents))
		{
			return [];
		}

		$items = [];
		foreach ($blockContents as $content)
		{
			foreach ($this->extractAnchorsFromContent($content) as $anchor)
			{
				$items[] = $anchor;
			}
		}

		if (empty($items))
		{
			return [];
		}

		// De-dupe while preserving order, then resolve titles from b_landing.
		$seen = [];
		$ordered = [];
		foreach ($items as $item)
		{
			$key = $item['landingId'];
			if (isset($seen[$key]))
			{
				continue;
			}
			$seen[$key] = true;
			$ordered[] = $item;
		}

		$titles = $this->loadLandingTitles(array_column($ordered, 'landingId'));

		$result = [];
		foreach ($ordered as $item)
		{
			$pageId = (int)$item['landingId'];
			$title = $item['title'] !== '' ? $item['title'] : ($titles[$pageId] ?? '');
			if ($title === '')
			{
				continue;
			}
			$result[] = [
				'title' => $title,
				'pageId' => $pageId,
				'href' => $item['href'],
				'depth' => 0,
			];
		}

		return $result;
	}

	private function loadAreaLandingIds(int $siteId): array
	{
		$rows = TemplateRefTable::getList([
			'select' => ['LANDING_ID'],
			'filter' => [
				'=ENTITY_TYPE' => 'S',
				'=ENTITY_ID' => $siteId,
			],
		])->fetchAll();

		$ids = [];
		foreach ($rows as $row)
		{
			$id = (int)($row['LANDING_ID'] ?? 0);
			if ($id > 0)
			{
				$ids[$id] = true;
			}
		}

		return array_keys($ids);
	}

	/**
	 * ACTIVE='Y' is filtered for the same reason as in {@see BlockReader::listForPages}: a reader of
	 * the base never sees an inactive block, so a menu hidden by its author must not end up in the
	 * table of contents of the converted base.
	 *
	 * ID closes the ordering, as it does in `BlockReader::listForPages`: blocks sharing a SORT would
	 * otherwise come back in whatever order the database picked, and that order is the order of the
	 * table of contents of the converted base.
	 *
	 * @param int[] $landingIds
	 * @return string[] block CONTENT bodies, ordered by landing, then SORT, then ID.
	 */
	private function loadBlockContents(array $landingIds): array
	{
		if (empty($landingIds))
		{
			return [];
		}

		$rows = BlockTable::getList([
			'select' => ['CONTENT'],
			'filter' => [
				'=LID' => $landingIds,
				'=ACTIVE' => 'Y',
				'=DELETED' => 'N',
				'=PUBLIC' => 'N',
			],
			'order' => ['LID' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		$contents = [];
		foreach ($rows as $row)
		{
			$content = (string)($row['CONTENT'] ?? '');
			if ($content !== '' && stripos($content, '#landing') !== false)
			{
				$contents[] = $content;
			}
		}

		return $contents;
	}

	/**
	 * @return array<int, array{title:string, href:string, landingId:int}>
	 */
	private function extractAnchorsFromContent(string $content): array
	{
		if (!preg_match_all(
			'~<a\b[^>]*\bhref\s*=\s*["\']#landing(?P<lid>\d+)["\'][^>]*>(?P<text>.*?)</a>~is',
			$content,
			$matches,
			PREG_SET_ORDER,
		))
		{
			return [];
		}

		$out = [];
		foreach ($matches as $m)
		{
			$text = trim(html_entity_decode(strip_tags($m['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			$out[] = [
				'title' => preg_replace('/\s+/u', ' ', $text) ?? $text,
				'href' => '#landing' . $m['lid'],
				'landingId' => (int)$m['lid'],
			];
		}

		return $out;
	}

	/**
	 * @param int[] $landingIds
	 * @return array<int, string> landingId -> TITLE
	 */
	private function loadLandingTitles(array $landingIds): array
	{
		$landingIds = array_values(array_unique(array_filter(array_map('intval', $landingIds))));
		if (empty($landingIds))
		{
			return [];
		}

		$rows = LandingScope::runInKnowledgeScope(static fn(): array => LandingTable::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => [
				'=ID' => $landingIds,
				'=DELETED' => 'N',
			],
		])->fetchAll());

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['ID']] = (string)($row['TITLE'] ?? '');
		}

		return $result;
	}
}
