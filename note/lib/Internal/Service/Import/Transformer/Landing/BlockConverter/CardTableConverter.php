<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockMediaContext;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\TableRenderer;

/**
 * Renders landing "card list" blocks (article listings, dated cards, news rows - anything built
 * from repeated `.landing-card` / `.landing-block-card` / `.landing-block-node-card` articles that
 * are NOT slider slides) as a header-less markdown table, one row per card.
 *
 * What the block carries outside its cards - the section heading, the intro text, a lead image -
 * leads the output, above the table. The cards are cut out of that html, so the intro can never
 * repeat what the rows already say.
 *
 * Columns are adaptive: Image | Text | Link, and a column is emitted only when at least one card
 * actually has that kind of content (no images anywhere -> no image column, and so on). The table
 * carries content only - the GFM-mandatory header row is left blank so no label row is shown.
 *
 * A card's title and body go through {@see HtmlToMarkdown::convertCell()}, so the formatting the
 * designer put in the card (bold, links, a list) survives on the single line a markdown cell has;
 * a table inside a card degrades to the text of its cells, having no cell of its own to live in.
 *
 * Images use the card's own `data-fileid` (the exact b_file id) so retina `@2x` duplicates that
 * pollute the block's file list never shift or repeat pictures across cards; external `http(s)`
 * images fall back to the download placeholder.
 */
final class CardTableConverter implements Converter
{
	/** Title node classes, most specific first. */
	private const TITLE_SELECTORS = ['landing-block-node-card-title', 'landing-block-node-title'];
	/** Body/description node classes, most specific first. */
	private const TEXT_SELECTORS = ['landing-block-node-card-text', 'landing-block-node-text', 'landing-block-node-subtitle'];

	public function __construct(
		private readonly HtmlToMarkdown $html = new HtmlToMarkdown(),
		private readonly FileReader $files = new FileReader(),
	)
	{
	}

	/**
	 * True when the content is a static card list of 2+ cards (a single card is left to the
	 * normal converters - a one-row table is noise) out of which these selectors do read
	 * something. A card layout they cannot read (the FAQ blocks put question and answer in
	 * `landing-block-faq-*` classes) belongs to another converter, not to an empty table.
	 */
	public static function matches(string $content): bool
	{
		$cards = self::listCards($content);
		if (count($cards) < 2)
		{
			return false;
		}

		foreach ($cards as $card)
		{
			if (
				self::cardImage($card['html']) !== ''
				|| self::cardTextHtml($card['html']) !== ''
				|| self::cardLink($card['html']) !== ''
			)
			{
				return true;
			}
		}

		return false;
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		$cards = self::listCards($content);
		if (count($cards) < 2)
		{
			return '';
		}

		$blockId = (int)($block['ID'] ?? 0);
		// One cursor per block, shared by the lead content and the cards: an image without an
		// explicit data-fileid takes the block's next FILE_ID in document order.
		$media = BlockMediaContext::forContent(
			$blockId > 0 ? $this->files->filesForBlock($blockId) : [],
			$content,
		);

		$rows = $this->cardRows($cards, $media);
		$columns = self::presentColumns($rows);
		if ($columns === [])
		{
			return '';
		}

		$table = $this->renderTable($rows, $columns);
		// The heading and the intro of the block: everything but the cards, which are rendered as
		// rows below it. An empty result simply means the block is nothing but its cards.
		$lead = trim($this->html->convert(
			BlockHtmlExtractor::stripNodes($content, BlockHtmlExtractor::CARD_CLASSES),
			$media,
		));

		return $lead === '' ? $table : $lead . "\n\n" . $table;
	}

	/**
	 * @return array<int, array{html: string, slide: bool}> cards that are not slider slides
	 */
	private static function listCards(string $content): array
	{
		return array_values(array_filter(
			BlockHtmlExtractor::extractCardNodes($content),
			static fn(array $card): bool => !$card['slide'],
		));
	}

	/**
	 * @param array<int, array{html: string, slide: bool}> $cards
	 * @return array<int, array{image: string, text: string, link: string}>
	 */
	private function cardRows(array $cards, BlockMediaContext $media): array
	{
		$rows = [];
		foreach ($cards as $card)
		{
			$rows[] = [
				'image' => self::cardImage($card['html']),
				'text' => $this->cardText($card['html'], $media),
				'link' => self::cardLink($card['html']),
			];
		}

		return $rows;
	}

	/**
	 * @param array<int, array{image: string, text: string, link: string}> $rows
	 * @return string[] columns at least one card fills
	 */
	private static function presentColumns(array $rows): array
	{
		$present = ['image' => false, 'text' => false, 'link' => false];
		foreach ($rows as $row)
		{
			foreach ($present as $key => $seen)
			{
				$present[$key] = $seen || $row[$key] !== '';
			}
		}

		return array_keys(array_filter($present));
	}

	private static function cardImage(string $cardHtml): string
	{
		$fileId = BlockHtmlExtractor::firstFileId($cardHtml);
		if ($fileId !== null)
		{
			return '![](' . LandingSource::ATTACHMENT_SCHEME . $fileId . ')';
		}

		$src = BlockHtmlExtractor::extractImageDescriptors($cardHtml)[0]['src'] ?? '';
		if ($src === '')
		{
			return '';
		}

		return '![](' . (LandingSource::externalAttachmentUrl($src) ?? $src) . ')';
	}

	/**
	 * The card's title and body as the markdown of one cell.
	 */
	private function cardText(string $cardHtml, BlockMediaContext $media): string
	{
		$title = $this->html->convertCell(self::firstNodeHtml($cardHtml, self::TITLE_SELECTORS), $media);
		$body = $this->html->convertCell(self::firstNodeHtml($cardHtml, self::TEXT_SELECTORS), $media);

		$parts = [];
		if ($title !== '')
		{
			// A title that already carries emphasis of its own is not wrapped a second time.
			$parts[] = str_contains($title, '**') ? $title : '**' . $title . '**';
		}
		if ($body !== '')
		{
			$parts[] = $body;
		}

		return implode(' ', $parts);
	}

	private static function cardLink(string $cardHtml): string
	{
		$link = BlockHtmlExtractor::firstLink($cardHtml);

		// The contact cards keep an unresolved landing substitution in both halves of the link
		// (`[Call Us #crmPhoneTitle1](tel:#crmPhone1)`), and this converter builds the link from the
		// extracted attributes instead of walking the markup, so it has to drop the mask itself - by
		// the one rule of HtmlToMarkdown, not a spelling of its own (P7.T8). A bare `#` fragment is
		// still emitted verbatim: that one is a jump of the page and is downgraded downstream.
		$label = trim(HtmlToMarkdown::dropSubstitutions($link['text']));
		$href = HtmlToMarkdown::isSubstitutionHref($link['href']) ? '' : $link['href'];
		if ($href === '' && $label === '')
		{
			return '';
		}

		// The label is escaped as the markdown text it becomes; the href is left to the link rule,
		// which escapes what would end the link target early.
		$text = BlockHtmlExtractor::escapeMarkdownInline($label !== '' ? $label : $href);
		if ($href === '')
		{
			return $text;
		}

		// The one link rule of HtmlToMarkdown, same as inline links: a landing `#landing<id>`
		// cross-page href reaches LandingSource::resolveBodyInternalLinks, which rewrites or
		// downgrades it later, and a contact card - `tel:`/`mailto:` is what these cards are for -
		// keeps its address in the text instead of a link the editor would strip to the label.
		return HtmlToMarkdown::linkOrText($text, $href);
	}

	/**
	 * Html of the card's title or body - whichever of them the selectors read first. Used to decide
	 * whether the block is a card list at all, without paying for the markdown conversion.
	 */
	private static function cardTextHtml(string $cardHtml): string
	{
		return self::firstNodeHtml($cardHtml, array_merge(self::TITLE_SELECTORS, self::TEXT_SELECTORS));
	}

	/**
	 * Inner html of the first node among $selectors that holds text.
	 *
	 * @param string[] $selectors
	 */
	private static function firstNodeHtml(string $cardHtml, array $selectors): string
	{
		foreach ($selectors as $selector)
		{
			$html = BlockHtmlExtractor::extractBySelector($cardHtml, $selector);
			if (trim(strip_tags($html)) !== '')
			{
				return $html;
			}
		}

		return '';
	}

	/**
	 * @param array<int, array{image: string, text: string, link: string}> $rows
	 * @param string[] $columns
	 */
	private function renderTable(array $rows, array $columns): string
	{
		$colCount = count($columns);

		// GFM requires a header + delimiter row; the header cells are left empty so the table
		// shows content only (no label row), per the import spec.
		$lines = [
			'|' . str_repeat('  |', $colCount),
			'|' . str_repeat(' --- |', $colCount),
		];
		foreach ($rows as $row)
		{
			$cells = [];
			foreach ($columns as $column)
			{
				$cells[] = TableRenderer::flattenCell($row[$column] ?? '');
			}
			$lines[] = '| ' . implode(' | ', $cells) . ' |';
		}

		return implode("\n", $lines);
	}
}
