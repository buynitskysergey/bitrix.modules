<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockMediaContext;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block codes matching /^\d+\.\d+\.(spoiler|accordion)/ - collapsible sections.
 *
 * Each pair of (.landing-block-node-title, .landing-block-node-text) is flattened
 * into `#### {title}` followed by the text body. Order preserved (PRD section 5 cat B).
 */
final class AccordionConverter implements Converter
{
	public function __construct(
		private readonly HtmlToMarkdown $html = new HtmlToMarkdown(),
		private readonly FileReader $files = new FileReader(),
	)
	{
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		if ($content === '')
		{
			return '';
		}

		// One cursor per block: shared across every section body so image FILE_IDs
		// are consumed in document order and not reset between sections.
		$blockId = (int)($block['ID'] ?? 0);
		$media = BlockMediaContext::forContent(
			$blockId > 0 ? $this->files->filesForBlock($blockId) : [],
			$content,
		);

		$titles = BlockHtmlExtractor::extractAllBySelector($content, 'landing-block-node-title');
		$bodies = BlockHtmlExtractor::extractAllBySelector($content, 'landing-block-node-text');

		$count = max(count($titles), count($bodies));
		if ($count === 0)
		{
			return trim($this->html->convert($content, $media));
		}

		$parts = [];
		for ($i = 0; $i < $count; $i++)
		{
			$titleHtml = $titles[$i] ?? '';
			$bodyHtml = $bodies[$i] ?? '';

			$title = trim(strip_tags(html_entity_decode($titleHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
			$body = trim($this->html->convert($bodyHtml, $media));

			if ($title === '' && $body === '')
			{
				continue;
			}

			$section = '';
			if ($title !== '')
			{
				$section .= '#### ' . $title;
			}
			if ($body !== '')
			{
				$section .= ($section !== '' ? "\n\n" : '') . $body;
			}

			$parts[] = $section;
		}

		return implode("\n\n", $parts);
	}
}
