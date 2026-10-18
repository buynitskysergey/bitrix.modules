<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block: 27.2.one_col_full_title -> "# title".
 * Falls back to text inside .landing-block-node-title (defaults to first <h*>).
 */
final class HeaderConverter implements Converter
{
	public function __construct(private readonly HtmlToMarkdown $html = new HtmlToMarkdown())
	{
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		$titleHtml = BlockHtmlExtractor::extractBySelector($content, 'landing-block-node-title');
		if ($titleHtml === '')
		{
			$titleHtml = BlockHtmlExtractor::extractFirstHeading($content);
		}

		$text = trim(strip_tags(html_entity_decode($titleHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		if ($text === '')
		{
			$text = trim($this->html->convert($content));
			if ($text === '')
			{
				return '';
			}
		}

		// No explicit anchor: the note editor derives a heading's anchor id from its
		// text (slugify), and supports no `{#id}` markdown syntax - emitting the
		// landing block ANCHOR here would only leak as literal "{#bNN}" text into the
		// heading and corrupt the auto-generated slug.
		return '# ' . $text;
	}
}
