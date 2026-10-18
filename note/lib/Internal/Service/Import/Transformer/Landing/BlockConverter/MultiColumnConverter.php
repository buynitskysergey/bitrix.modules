<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockMediaContext;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block codes matching /^\d+\.\d+\.(two|three|four)_col/ - multi-column blocks.
 *
 * note doesn't support multi-column layouts, so columns are normally rendered
 * sequentially with a blank line between them (PRD section 5 category B). The one exception is
 * the classic "image beside text" two-column block: there the image is emitted as a
 * float (align=left/right by its column side) so the text wraps around it the way it did
 * on the landing, instead of the image and text stacking vertically.
 */
final class MultiColumnConverter implements Converter
{
	private const COLUMN_SELECTORS = [
		'landing-block-node-column',
		'landing-block-node-col',
		'g-col',
	];

	// Width a floated image is capped/defaulted to so the wrapped text keeps a usable column.
	// Mirrors the editor's float cap (ResizableMediaMixin.FLOAT_MAX_PCT = 70).
	private const FLOAT_MAX_WIDTH_PCT = 70;
	private const FLOAT_DEFAULT_WIDTH_PCT = 45;

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

		// One cursor per block: shared across all columns so image FILE_IDs are
		// consumed in document order and not reset between columns.
		$blockId = (int)($block['ID'] ?? 0);
		$media = BlockMediaContext::forContent(
			$blockId > 0 ? $this->files->filesForBlock($blockId) : [],
			$content,
		);

		$columns = [];
		foreach (self::COLUMN_SELECTORS as $selector)
		{
			$columns = BlockHtmlExtractor::extractAllBySelector($content, $selector);
			if (!empty($columns))
			{
				break;
			}
		}

		if (empty($columns))
		{
			// Landing designer markup usually lays columns out as Bootstrap `.row > .col-*`.
			$columns = BlockHtmlExtractor::extractBootstrapColumns($content);
		}

		if (empty($columns))
		{
			// Fallback: render the whole CONTENT.
			$columns = [$content];
		}

		// Render each column in document order (one shared media cursor, so image FILE_IDs are
		// consumed left-to-right even when the output is later reordered for the float case).
		$parts = [];
		foreach ($columns as $column)
		{
			$md = trim($this->html->convert($column, $media));
			if ($md !== '')
			{
				$parts[] = $md;
			}
		}

		return $this->layoutColumns($parts);
	}

	/**
	 * "Image beside text": a two-column block where exactly one column is a lone image and the
	 * other is text renders as a floated image followed by the text, so the text wraps around it
	 * (image column on the left -> float left; on the right -> float right). Every other shape keeps
	 * the plain sequential layout.
	 *
	 * @param string[] $parts rendered column markdown, document order
	 */
	private function layoutColumns(array $parts): string
	{
		if (count($parts) === 2)
		{
			[$first, $second] = $parts;
			$firstIsImage = $this->isSingleImage($first);
			$secondIsImage = $this->isSingleImage($second);

			if ($firstIsImage && !$secondIsImage)
			{
				return $this->floatImage($first, 'left') . "\n\n" . $second;
			}
			if ($secondIsImage && !$firstIsImage)
			{
				// Image floats right; it must still precede the text in document order so the
				// text wraps beside it.
				return $this->floatImage($second, 'right') . "\n\n" . $first;
			}
		}

		return implode("\n\n", $parts);
	}

	private function isSingleImage(string $markdown): bool
	{
		return preg_match('/^!\[[^\]]*\]\(\S+\)$/', trim($markdown)) === 1;
	}

	/**
	 * Injects `a=<align>` (and a sane float width) into an image placeholder's positioning
	 * query, preserving its scheme/id and any existing width. Leaves non-image input untouched.
	 */
	private function floatImage(string $imageMarkdown, string $align): string
	{
		if (preg_match('/^!\[(?P<alt>[^\]]*)\]\((?P<url>\S+)\)$/', trim($imageMarkdown), $m) !== 1)
		{
			return $imageMarkdown;
		}

		[$base, $query] = array_pad(explode('?', $m['url'], 2), 2, '');
		parse_str($query, $params);

		$params['a'] = $align;
		$width = (int)($params['w'] ?? 0);
		if ($width < 1 || $width > self::FLOAT_MAX_WIDTH_PCT)
		{
			$params['w'] = self::FLOAT_DEFAULT_WIDTH_PCT;
		}

		return '![' . $m['alt'] . '](' . $base . '?' . http_build_query($params) . ')';
	}
}
