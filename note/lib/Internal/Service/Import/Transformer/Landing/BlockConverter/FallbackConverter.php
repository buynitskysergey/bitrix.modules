<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockMediaContext;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Catch-all for unknown block codes: feed the whole block CONTENT through HtmlToMarkdown.
 *
 * Media-aware: unmapped blocks may still carry images / video embeds outside their text
 * nodes (cards, banners). Those are converted with a per-block {@see BlockMediaContext} so
 * their landing FILE_IDs are resolved (matching ImageConverter output) instead of dropped.
 */
final class FallbackConverter implements Converter
{
	public function __construct(
		private readonly HtmlToMarkdown $html = new HtmlToMarkdown(),
		private readonly FileReader $files = new FileReader(),
	) {
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		if ($content === '')
		{
			return '';
		}

		$blockId = (int)($block['ID'] ?? 0);
		// Shared cursor over the block's FILE_IDs, consumed in document order. Each image without
		// an explicit data-fileid takes the next id (see HtmlToMarkdown::renderImage).
		$media = BlockMediaContext::forContent(
			$blockId > 0 ? $this->files->filesForBlock($blockId) : [],
			$content,
		);

		// Convert the whole block in a single pass so text, headings and media keep their source
		// order - a card's header must stay above its body, an intro paragraph above its image.
		// HtmlToMarkdown recurses through the wrapper divs, so nothing outside the text nodes is
		// dropped. (An earlier version converted the `.landing-block-node-text` nodes first and the
		// remaining media second; that recovered the media but flipped any header/text order.)
		return trim($this->html->convert($content, $media));
	}
}
