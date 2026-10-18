<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing;

use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Integration\Landing\PageReader;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\BlockHtmlExtractor;

/**
 * Renders the navigation menu of a converted knowledge base as a markdown table of contents. The
 * source puts it on top of the content of the home page, which the import writes into the
 * description of the base - not into the first document of the collection, where an arbitrary
 * section or page used to receive the navigation of the whole base.
 *
 * Menu items point at other pages of the same site through the landing `#landing<id>` href form,
 * which no reader of a note document can follow. Each is rendered as the public landing address of
 * the page (`[Title](/knowledge/<siteCode>/<pageCode>/)`) instead: the fill step runs all content
 * through {@see \Bitrix\Note\Internal\Service\Import\Transformer\LandingMentionTransformer}, which
 * rewrites the address of every page of this import into `/note/document/<id>/`. An item pointing
 * at a page outside the import keeps the landing address, which landing still serves.
 *
 * Both halves of the item go in by the one link rule ({@see HtmlToMarkdown::linkOrText()}), and that
 * is not cosmetic here: the pass that rewrites these addresses reads a label as everything up to the
 * first `]`, so a title spelling a bracket of its own would keep the whole item pointing at landing.
 * This is the table of contents in the description of the base - the first screen a reader opens.
 */
final class MenuConverter
{
	public function __construct(private readonly PageReader $pageReader = new PageReader())
	{
	}

	/**
	 * The rows of every item are read at once: the menu of a base names as many pages as its author
	 * put in it, and one read per item is one query per line of the table of contents.
	 *
	 * @param array<int, array{title:string, pageId:int, href:string, depth:int}> $menuItems
	 */
	public function convert(array $menuItems, string $siteCode): string
	{
		if (empty($menuItems))
		{
			return '';
		}

		$pages = $this->pageReader->getByIds(array_column($menuItems, 'pageId'));

		$lines = [];
		foreach ($menuItems as $item)
		{
			$page = $pages[(int)$item['pageId']] ?? null;
			if ($page === null)
			{
				continue;
			}

			$pageCode = ($page['CODE'] !== null && $page['CODE'] !== '')
				? (string)$page['CODE']
				: (string)$page['ID'];

			$title = $item['title'] !== '' ? $item['title'] : ($page['TITLE'] ?? '');
			if ($title === '')
			{
				continue;
			}

			$depth = max(0, min(2, (int)$item['depth']));
			$indent = str_repeat('  ', $depth);
			$href = '/knowledge/' . $siteCode . '/' . $pageCode . '/';

			// The title is the page's own text, read out of the markup by MenuReader and put between
			// the brackets of a label - so it goes in by the same rule as every other label of the
			// conversion: markup characters escaped, square brackets dropped rather than escaped.
			$lines[] = $indent . '- ' . HtmlToMarkdown::linkOrText(
				BlockHtmlExtractor::escapeMarkdownInline($title),
				$href,
			);
		}

		if (empty($lines))
		{
			return '';
		}

		$list = implode("\n", $lines);

		// The heading is baked into the content once, at import time, and no later translation
		// reaches an already imported base. So on a locale the phrase has not been translated for,
		// the list goes in without a heading rather than under an empty `##` one.
		$heading = (string)Loc::getMessage('NOTE_IMPORT_LANDING_MENU_SECTION');

		return $heading !== '' ? '## ' . $heading . "\n\n" . $list : $list;
	}
}
