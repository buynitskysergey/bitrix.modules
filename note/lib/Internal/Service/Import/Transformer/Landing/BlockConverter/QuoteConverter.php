<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockMediaContext;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block: 27.5.one_col_fix_text_with_border -> '> blockquote'.
 */
final class QuoteConverter implements Converter
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
		$snippet = BlockHtmlExtractor::extractBySelector($content, 'landing-block-node-text');
		if ($snippet === '')
		{
			$snippet = $content;
		}

		$blockId = (int)($block['ID'] ?? 0);
		$media = BlockMediaContext::forContent(
			$blockId > 0 ? $this->files->filesForBlock($blockId) : [],
			$content,
		);

		$inner = trim($this->html->convert($snippet, $media));
		if ($inner === '')
		{
			return '';
		}

		$lines = preg_split("/\r?\n/", $inner) ?: [];
		$quoted = array_map(static fn(string $l): string => '> ' . ltrim($l), $lines);

		return implode("\n", $quoted);
	}
}
