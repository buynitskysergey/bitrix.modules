<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockMediaContext;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block codes matching /^\d+\.\d+\.list/ - bulleted / numbered list blocks.
 *
 * Delegates to HtmlToMarkdown for nested-list rendering (2-space indent per level).
 */
final class ListConverter implements Converter
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

		$blockId = (int)($block['ID'] ?? 0);
		$media = BlockMediaContext::forContent(
			$blockId > 0 ? $this->files->filesForBlock($blockId) : [],
			$content,
		);

		$snippets = BlockHtmlExtractor::extractAllBySelector($content, 'landing-block-node-text');
		if (empty($snippets))
		{
			$snippets = [$content];
		}

		$parts = [];
		foreach ($snippets as $snippet)
		{
			$md = trim($this->html->convert($snippet, $media));
			if ($md !== '')
			{
				$parts[] = $md;
			}
		}

		return implode("\n\n", $parts);
	}
}
