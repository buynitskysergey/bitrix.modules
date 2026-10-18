<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

/**
 * Pulls inner HTML for `.landing-block-node-*` nodes out of a block CONTENT blob.
 *
 * Landing block content stores designer markup with stable CSS classes like
 * .landing-block-node-title / -text / -img / -embed / -button. Converters use this
 * helper to fetch a single section's HTML without paying for full DOM walks.
 */
final class BlockHtmlExtractor
{
	/**
	 * Markers of a landing "card" - the repeated unit of a card list, a slider slide and a
	 * question-answer pair. Shared so a converter can both read the cards ({@see extractCardNodes()})
	 * and render what lies outside them ({@see stripNodes()}).
	 */
	public const CARD_CLASSES = ['landing-card', 'landing-block-card', 'landing-block-node-card'];
	/* Tunable reference content width (px) used to convert absolute pixel widths into a percentage. */
	private const REFERENCE_CONTENT_PX = 900;

	public static function extractBySelector(string $html, string $cssClass): string
	{
		$matches = self::extractAllBySelector($html, $cssClass);
		return $matches[0] ?? '';
	}

	/**
	 * @return string[] inner HTML of every matched element, document order.
	 */
	public static function extractAllBySelector(string $html, string $cssClass): array
	{
		if ($html === '' || $cssClass === '')
		{
			return [];
		}

		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return [];
		}

		$xpath = new \DOMXPath($dom);
		$expr = sprintf(
			'//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
			$cssClass,
		);
		$nodes = $xpath->query($expr);
		if ($nodes === false)
		{
			return [];
		}

		$result = [];
		foreach ($nodes as $node)
		{
			$result[] = self::innerHtml($node);
		}

		return $result;
	}

	/**
	 * Extracts the column bodies of the first Bootstrap grid `.row` in the HTML.
	 *
	 * Landing's designer markup lays two-/three-column blocks out as `.row > .col-md-6`
	 * (and friends) rather than the `.landing-block-node-column` markers, so MultiColumnConverter
	 * uses this as a fallback to recover the per-column HTML in document order. Returns an empty
	 * array when there is no `.row` with column children.
	 *
	 * @return string[] inner HTML of each column, left-to-right
	 */
	public static function extractBootstrapColumns(string $html): array
	{
		if ($html === '')
		{
			return [];
		}

		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return [];
		}

		$xpath = new \DOMXPath($dom);
		$rows = $xpath->query('(//*[contains(concat(" ", normalize-space(@class), " "), " row ")])[1]');
		if ($rows === false || $rows->length === 0)
		{
			return [];
		}

		$row = $rows->item(0);
		if (self::hasContentOutsideRow($xpath, $row))
		{
			// The block carries real content outside the grid row - a section heading, intro/outro
			// paragraphs, a second row, a standalone image, ... Returning only the columns would
			// silently drop it, so bail and let the caller render the whole block instead.
			return [];
		}

		$columns = [];
		foreach ($row->childNodes as $child)
		{
			if ($child instanceof \DOMElement && self::hasBootstrapColumnClass($child->getAttribute('class')))
			{
				$columns[] = self::innerHtml($child);
			}
		}

		return $columns;
	}

	/**
	 * True when the document has meaningful content - non-whitespace text or a media element -
	 * that lives outside the given row subtree. Guards {@see extractBootstrapColumns()} against
	 * dropping block headings / intro text that sit next to (not inside) the grid row.
	 */
	private static function hasContentOutsideRow(\DOMXPath $xpath, \DOMNode $row): bool
	{
		$totalText = $xpath->query('//text()[normalize-space()]');
		$rowText = $xpath->query('.//text()[normalize-space()]', $row);
		if ($totalText !== false && $rowText !== false && $totalText->length > $rowText->length)
		{
			return true;
		}

		$mediaExpr = '//img | //picture | //video | //iframe | //object | //embed | //svg';
		$rowMediaExpr = './/img | .//picture | .//video | .//iframe | .//object | .//embed | .//svg';
		$totalMedia = $xpath->query($mediaExpr);
		$rowMedia = $xpath->query($rowMediaExpr, $row);

		return $totalMedia !== false && $rowMedia !== false && $totalMedia->length > $rowMedia->length;
	}

	private static function hasBootstrapColumnClass(string $class): bool
	{
		foreach (preg_split('/\s+/', trim(mb_strtolower($class))) ?: [] as $token)
		{
			// col | col-6 | col-md | col-md-6 | col-auto | col-md-auto
			if (preg_match('/^col(?:-(?:xs|sm|md|lg|xl|xxl))?(?:-(?:\d{1,2}|auto))?$/', $token) === 1)
			{
				return true;
			}
		}

		return false;
	}

	public static function extractFirstHeading(string $html): string
	{
		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return '';
		}

		foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag)
		{
			$nodes = $dom->getElementsByTagName($tag);
			if ($nodes->length > 0)
			{
				return self::innerHtml($nodes->item(0));
			}
		}

		return '';
	}

	/**
	 * @return string[] src attribute values of every <img> in document order.
	 */
	public static function extractImageSources(string $html): array
	{
		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return [];
		}

		$result = [];
		foreach ($dom->getElementsByTagName('img') as $img)
		{
			$src = trim($img->getAttribute('src'));
			if ($src !== '')
			{
				$result[] = $src;
			}
		}

		return $result;
	}

	/**
	 * @return array<int, array{src:string, alt:string, align:?string, width:?int}>
	 */
	public static function extractImageDescriptors(string $html): array
	{
		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return [];
		}

		$result = [];
		foreach ($dom->getElementsByTagName('img') as $img)
		{
			$src = trim($img->getAttribute('src'));
			if ($src === '')
			{
				continue;
			}
			$result[] = [
				'src' => $src,
				'alt' => $img->getAttribute('alt'),
				'align' => self::extractAlign($img),
				'width' => self::extractWidthPercent($img),
			];
		}

		return $result;
	}

	/**
	 * Landing "card" collections - an article/div carrying any of the card markers
	 * (`landing-card` / `landing-block-card` / `landing-block-node-card`). Returns each card's
	 * inner HTML in document order, flagging slider slides (`js-slide`) so callers can render a
	 * static card list (table) and a slider (sequential media) differently.
	 *
	 * @return array<int, array{html: string, slide: bool}>
	 */
	public static function extractCardNodes(string $html): array
	{
		if ($html === '' || stripos($html, 'card') === false)
		{
			return [];
		}

		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return [];
		}

		$xpath = new \DOMXPath($dom);
		$nodes = $xpath->query(self::classExpression(self::CARD_CLASSES));
		if ($nodes === false)
		{
			return [];
		}

		$cards = [];
		foreach ($nodes as $node)
		{
			if (!$node instanceof \DOMElement)
			{
				continue;
			}
			$class = ' ' . mb_strtolower((string)preg_replace('/\s+/', ' ', trim($node->getAttribute('class')))) . ' ';
			$cards[] = [
				'html' => self::innerHtml($node),
				'slide' => str_contains($class, ' js-slide '),
			];
		}

		return $cards;
	}

	/**
	 * First `data-fileid` (a b_file id) found on any element - landing stores managed images
	 * this way both as `<img data-fileid>` and as CSS `background-image` divs. Returns null when
	 * there is no positive id.
	 */
	public static function firstFileId(string $html): ?int
	{
		if ($html === '' || stripos($html, 'data-fileid') === false)
		{
			return null;
		}

		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return null;
		}

		$nodes = (new \DOMXPath($dom))->query('//*[@data-fileid]');
		if ($nodes === false || $nodes->length === 0)
		{
			return null;
		}

		$fileId = (int)$nodes->item(0)->getAttribute('data-fileid');

		return $fileId > 0 ? $fileId : null;
	}

	/**
	 * First `<a>` in the html.
	 *
	 * @return array{href: string, text: string} empty strings when there is no link.
	 */
	public static function firstLink(string $html): array
	{
		$empty = ['href' => '', 'text' => ''];
		if ($html === '' || stripos($html, '<a') === false)
		{
			return $empty;
		}

		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return $empty;
		}

		$links = $dom->getElementsByTagName('a');
		if ($links->length === 0)
		{
			return $empty;
		}
		$a = $links->item(0);

		return [
			'href' => trim((string)$a->getAttribute('href')),
			'text' => trim((string)preg_replace('/\s+/u', ' ', $a->textContent)),
		];
	}

	/**
	 * Reads a landing pseudo-url (`data-pseudo-url='{"href":..,"text":..,"enabled":true}'`) from
	 * the first element carrying it. Returns href/text only when the link is enabled.
	 *
	 * @return array{href: string, text: string}
	 */
	public static function pseudoUrl(string $html): array
	{
		$empty = ['href' => '', 'text' => ''];
		if ($html === '' || stripos($html, 'data-pseudo-url') === false)
		{
			return $empty;
		}

		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return $empty;
		}

		$nodes = (new \DOMXPath($dom))->query('//*[@data-pseudo-url]');
		if ($nodes === false || $nodes->length === 0)
		{
			return $empty;
		}

		$raw = html_entity_decode((string)$nodes->item(0)->getAttribute('data-pseudo-url'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['enabled']))
		{
			return $empty;
		}

		return [
			'href' => trim((string)($data['href'] ?? '')),
			'text' => trim((string)($data['text'] ?? '')),
		];
	}

	/**
	 * Neutralises CommonMark inline metacharacters in extracted page text so that text interpolated
	 * into markdown structure (link labels, bold titles, table-cell bodies) cannot inject its own
	 * markup - a stray `[`, `*` or backtick in a slide caption stays literal instead of forging a
	 * link, emphasis run or code span when the document is rendered client-side.
	 *
	 * Backslash is escaped first, so the escapes added after it are not themselves re-escaped. The
	 * pipe `|` is deliberately left untouched: table cells escape it separately
	 * ({@see \Bitrix\Note\Internal\Service\Import\Transformer\Landing\TableRenderer::flattenCell()})
	 * and a literal pipe is harmless outside a table, so escaping it here would double-escape cells.
	 * Block-level leading markers (`#`, `>`, `-`) are not escaped - they only mangle formatting, never
	 * link/emphasis structure, and this text is emitted inline (labels, bold spans, cells), not as a
	 * standalone block. Text that ends up between the brackets of a `[...]` label takes one more step
	 * afterwards, see {@see dropLabelBrackets()}.
	 */
	public static function escapeMarkdownInline(string $text): string
	{
		return str_replace(
			['\\', '`', '*', '_', '[', ']', '<', '~'],
			['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '\\<', '\\~'],
			$text,
		);
	}

	/**
	 * The label rule: text about to stand between the brackets of `[...]` gives up its own square
	 * brackets instead of escaping them. Escaping keeps them readable, but the import reads its own
	 * links back, and every reader of a label matches one as "everything up to the first `]`" - the
	 * attachment placeholders of
	 * {@see \Bitrix\Note\Internal\Service\Import\Transformer\LandingMdTransformer::getPattern()}, the
	 * binding and public-address passes of
	 * {@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource}, the link markers of
	 * {@see \Bitrix\Note\Internal\Service\Import\Transformer\LandingMentionTransformer}, the column
	 * float of {@see MultiColumnConverter}. An escaped bracket falls outside that class and the whole
	 * link stops matching there: the attachment is never copied, the internal scheme is left in the
	 * document and the binding reaches an editor that rejects it. A label gives up the character
	 * rather than the image or the address it names.
	 *
	 * Expects text {@see escapeMarkdownInline()} (or the inline escape of
	 * {@see \Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown}) has already been
	 * through, and drops only the brackets that escape marked - those are the page's own words. An
	 * unescaped bracket belongs to markdown the conversion built itself and is left alone; the one
	 * shape that could bring such a bracket into a label, an image wrapped in a link, is taken out of
	 * the label before this runs
	 * ({@see \Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown::linkOrText()}).
	 */
	public static function dropLabelBrackets(string $escapedText): string
	{
		return trim(str_replace(['\\[', '\\]'], '', $escapedText));
	}

	/**
	 * Reads image alignment from inline style / class markers.
	 * Returns one of left|right|center, or null when no alignment is expressed.
	 */
	public static function extractAlign(\DOMElement $img): ?string
	{
		$style = mb_strtolower($img->getAttribute('style'));
		$class = mb_strtolower($img->getAttribute('class'));
		if (str_contains($style, 'float: left') || str_contains($style, 'float:left') || str_contains($class, '--left'))
		{
			return 'left';
		}
		if (str_contains($style, 'float: right') || str_contains($style, 'float:right') || str_contains($class, '--right'))
		{
			return 'right';
		}
		if (
			str_contains($style, 'text-align: center')
			|| str_contains($style, 'margin: 0 auto')
			|| str_contains($class, '--center')
		)
		{
			return 'center';
		}

		return null;
	}

	/**
	 * Reads image width as a 1..100 percentage. A percent width is taken verbatim;
	 * a pixel width (style or width attribute) is converted against a reference content width.
	 */
	public static function extractWidthPercent(\DOMElement $img): ?int
	{
		$style = mb_strtolower($img->getAttribute('style'));
		if (preg_match('/width\s*:\s*(\d{1,3})\s*%/', $style, $m))
		{
			$percent = (int)$m[1];

			return ($percent >= 1 && $percent <= 100) ? $percent : null;
		}
		/* Pixel width -> best-effort percentage of a reference content width. */
		if (
			preg_match('/width\s*:\s*(\d{2,4})\s*px/', $style, $m)
			|| preg_match('/^(\d{2,4})$/', trim($img->getAttribute('width')), $m)
		)
		{
			$px = (int)$m[1];
			$percent = (int)round($px / self::REFERENCE_CONTENT_PX * 100);

			return max(10, min(100, $percent));
		}

		// Landing sizes managed images (avatars, logos, member photos) with a fixed-pixel `g-width-<px>`
		// utility class (e.g. `.g-width-110 { width: 110px }`) rather than an inline width. Read it as a
		// last resort so a set of like-sized photos imports at one uniform width instead of each one
		// rendering at its own downloaded resolution - a 28.x team card mixes @1x managed avatars with
		// 200px stock photos, which otherwise come out different sizes. Responsive `g-width-100--sm`
		// variants are intentionally skipped (the digits must end the token).
		if (preg_match('/(?:^|\s)g-width-(\d{2,4})(?=\s|$)/', $img->getAttribute('class'), $m))
		{
			$px = (int)$m[1];
			$percent = (int)round($px / self::REFERENCE_CONTENT_PX * 100);

			return max(10, min(100, $percent));
		}

		return null;
	}

	/**
	 * Builds the `?w=..&a=..` suffix from an image descriptor (empty when no positioning).
	 */
	public static function positionQuerySuffix(?int $width, ?string $align): string
	{
		$params = [];
		if ($width !== null)
		{
			$params['w'] = $width;
		}
		if ($align !== null && $align !== 'center')
		{
			/* center is the editor default; omit it to keep the token clean. */
			$params['a'] = $align;
		}

		return $params === [] ? '' : '?' . http_build_query($params);
	}

	/**
	 * Returns the block HTML with every `.landing-block-node-text` subtree removed.
	 *
	 * Lets the fallback converter isolate media (images / embeds) that live outside the
	 * text nodes and convert it separately, so unmapped blocks do not silently drop their
	 * images and videos. Falls back to the input HTML when the DOM cannot be built.
	 */
	public static function stripTextNodes(string $html): string
	{
		return self::stripNodes($html, ['landing-block-node-text']);
	}

	/**
	 * Returns the html with every subtree carrying one of $cssClasses removed.
	 *
	 * Lets a converter render what lies AROUND the nodes it already handles itself: the block
	 * heading and intro next to the cards it renders as rows, the media it emits separately. Falls
	 * back to the input html when the DOM cannot be built.
	 *
	 * @param string[] $cssClasses
	 */
	public static function stripNodes(string $html, array $cssClasses): string
	{
		if ($html === '' || $cssClasses === [])
		{
			return $html;
		}

		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return $html;
		}

		$nodes = (new \DOMXPath($dom))->query(self::classExpression($cssClasses));
		if ($nodes === false)
		{
			return $html;
		}

		// Snapshot before mutating: removing a parent detaches its matched descendants too.
		$matched = [];
		foreach ($nodes as $node)
		{
			$matched[] = $node;
		}
		foreach ($matched as $node)
		{
			$node->parentNode?->removeChild($node);
		}

		$body = $dom->getElementsByTagName('body')->item(0);

		return $body === null ? $html : self::innerHtml($body);
	}

	/**
	 * For every element matching $cssClass, returns the requested attribute values (missing -> '').
	 * Unlike {@see extractAllBySelector()} this reads the matched element's OWN attributes instead of
	 * its inner HTML - needed for markers like `.landing-block-node-video` that carry their payload
	 * (data-source / data-src) on the element itself rather than on a child tag.
	 *
	 * @param string[] $attrNames
	 * @return array<int, array<string, string>> document order, one entry per matched element
	 */
	public static function extractElementAttributes(string $html, string $cssClass, array $attrNames): array
	{
		if ($html === '' || $cssClass === '' || $attrNames === [])
		{
			return [];
		}

		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return [];
		}

		$nodes = (new \DOMXPath($dom))->query(sprintf(
			'//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
			$cssClass,
		));
		if ($nodes === false)
		{
			return [];
		}

		$result = [];
		foreach ($nodes as $node)
		{
			if (!$node instanceof \DOMElement)
			{
				continue;
			}
			$attrs = [];
			foreach ($attrNames as $name)
			{
				$attrs[$name] = trim((string)$node->getAttribute($name));
			}
			$result[] = $attrs;
		}

		return $result;
	}

	public static function extractFirstAttribute(string $html, string $tagName, string $attrName): string
	{
		$dom = self::loadDom($html);
		if ($dom === null)
		{
			return '';
		}

		$nodes = $dom->getElementsByTagName($tagName);
		if ($nodes->length === 0)
		{
			return '';
		}
		/** @var \DOMElement $node */
		$node = $nodes->item(0);

		return trim((string)$node->getAttribute($attrName));
	}

	/**
	 * XPath selecting every element that carries any of the CSS classes.
	 *
	 * @param string[] $cssClasses
	 */
	private static function classExpression(array $cssClasses): string
	{
		$tests = array_map(
			static fn(string $class): string => sprintf(
				'contains(concat(" ", normalize-space(@class), " "), " %s ")',
				$class,
			),
			$cssClasses,
		);

		return '//*[' . implode(' or ', $tests) . ']';
	}

	private static function loadDom(string $html): ?\DOMDocument
	{
		$dom = new \DOMDocument('1.0', 'UTF-8');
		$wrapped = '<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>';

		$previous = libxml_use_internal_errors(true);
		try
		{
			$loaded = $dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		return $loaded ? $dom : null;
	}

	public static function innerHtml(\DOMNode $node): string
	{
		$html = '';
		foreach ($node->childNodes as $child)
		{
			$html .= $node->ownerDocument?->saveHTML($child) ?? '';
		}

		return $html;
	}
}
