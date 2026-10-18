<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockMediaContext;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Blocks whose content carries a `<table>`: the five table blocks of the landing delivery
 * (`67.1`-`67.5`) and `27.6.one_col_fix_text_with_headings` when the designer put a table in it.
 *
 * The whole block goes through {@see HtmlToMarkdown}, which renders the table itself through the
 * shared table renderer. That way the content living next to the table - the block heading, the
 * text column of `67.2`/`67.3`, the picture of `67.4`/`67.5` - is carried over in document order
 * instead of being dropped with everything that is not a table. A block with no table at all is
 * left to {@see TextConverter} (the dispatcher routes by markup, so it can reach this converter
 * through an explicit CODE as well).
 */
final class TableConverter implements Converter
{
	public function __construct(
		private readonly HtmlToMarkdown $html = new HtmlToMarkdown(),
		private readonly TextConverter $textFallback = new TextConverter(),
		private readonly FileReader $files = new FileReader(),
	)
	{
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		if (stripos($content, '<table') === false)
		{
			return $this->textFallback->convert($block);
		}

		// One media context per block so images across all cells and the neighbouring column consume
		// the block's FILE_IDs in document order (matching TextConverter/GalleryConverter binding).
		$blockId = (int)($block['ID'] ?? 0);
		$media = BlockMediaContext::forContent(
			$blockId > 0 ? $this->files->filesForBlock($blockId) : [],
			$content,
		);

		return trim($this->html->convert($content, $media));
	}
}
