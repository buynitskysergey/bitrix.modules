<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Internal\Service\Import\Source\LandingBlockMarkdownRenderer;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\AccordionConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\BlockHtmlExtractor;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\ButtonConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\CardTableConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\CodeConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\Converter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\FallbackConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\FaqConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\GalleryConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\HeaderConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\ImageConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\ListConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\MultiColumnConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\QuoteConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\SliderConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\TableConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\TextConverter;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\VideoConverter;

/**
 * Dispatcher for landing block CODE -> markdown converter (SDD ALG-02).
 *
 * Block CODE conventions (basic mapping mirrors landing/lib/note/source.php Source::BLOCKS;
 * regex extensions are added by phase P2.T6 for list/multi-column/accordion/button/gallery).
 *
 * Unknown codes go to {@see FallbackConverter}, which still renders them through
 * {@see HtmlToMarkdown} - such a block loses its layout, not its content.
 */
final class BlockConverter implements LandingBlockMarkdownRenderer
{
	private const TEXT_TABLE_CODE = '27.6.one_col_fix_text_with_headings';
	/** Media that survives conversion even when the block carries no text at all (SDD ALG-02). */
	private const MEDIA_MARKERS = ['<img', '<iframe', '<video', '<table', '<audio'];
	/** Call-to-action button node, the markup a button block is recognised by (P7.T1). */
	private const BUTTON_CLASS = 'landing-block-node-button';
	/** Wrapper class of the shaped `26.*` dividers; `26.separator` spells the same thing as an <hr>. */
	private const DIVIDER_CLASS = 'landing-block-separator';
	/** What a decorative divider is carried over as (P7.T2). */
	private const HORIZONTAL_RULE = '---';

	/** @var array<string, Converter> */
	private array $explicit;
	private TextConverter $textConverter;
	private TableConverter $tableConverter;
	private CardTableConverter $cardTableConverter;
	private SliderConverter $sliderConverter;
	private FaqConverter $faqConverter;
	private ButtonConverter $buttonConverter;
	private FallbackConverter $fallback;

	public function __construct(
		?FileReader $files = null,
		?HtmlToMarkdown $html = null,
		?HeaderConverter $headerConverter = null,
		?TextConverter $textConverter = null,
		?QuoteConverter $quoteConverter = null,
		?CodeConverter $codeConverter = null,
		?ImageConverter $imageConverter = null,
		?VideoConverter $videoConverter = null,
		?TableConverter $tableConverter = null,
		?FallbackConverter $fallback = null,
	)
	{
		$files = $files ?? new FileReader();
		$html = $html ?? new HtmlToMarkdown();
		$this->textConverter = $textConverter ?? new TextConverter($html, $files);
		$this->tableConverter = $tableConverter ?? new TableConverter($html, $this->textConverter, $files);
		$this->cardTableConverter = new CardTableConverter($html, $files);
		$this->sliderConverter = new SliderConverter($html, $files);
		$this->faqConverter = new FaqConverter($html, $files);
		$this->buttonConverter = new ButtonConverter();
		// The shared reader, like every other converter here: with one of its own the fallback would
		// be the single place still reading b_landing_file a block at a time.
		$this->fallback = $fallback ?? new FallbackConverter($html, $files);

		$this->explicit = [
			'27.2.one_col_full_title' => $headerConverter ?? new HeaderConverter($html),
			'27.5.one_col_fix_text_with_border' => $quoteConverter ?? new QuoteConverter($html, $files),
			'27.7.one_col_fix_text_on_bg' => $codeConverter ?? new CodeConverter($html),
			'32.2.img_one_big' => $imageConverter ?? new ImageConverter($files),
			'49.1.video_just_video' => $videoConverter ?? new VideoConverter($files),
		];

		// P2.T6 regex extensions - order matters, first match wins.
		$this->registerExtendedConverter('/^\d+\.\d+\.list/', new ListConverter($html, $files));
		$this->registerExtendedConverter('/^\d+\.\d+\.(two|three|four)_col/', new MultiColumnConverter($html, $files));
		$this->registerExtendedConverter('/^\d+\.\d+\.(spoiler|accordion)/', new AccordionConverter($html, $files));
		$this->registerExtendedConverter('/^\d+\.\d+\.(button|cta)/', $this->buttonConverter);
		$this->registerExtendedConverter('/^\d+\.\d+\.(gallery|slider)/', new GalleryConverter($files));
		// Carousels/sliders/galleries whose CODE lacks the `\d+.\d+.` prefix (e.g. `12.image_carousel_...`)
		// still route to the gallery so their images/videos are imported. Registered last so more
		// specific patterns above keep priority (e.g. `12.3.three_cols_carousel` stays MultiColumn).
		$this->registerExtendedConverter('/(?:carousel|slider|gallery)/', new GalleryConverter($files));
	}

	public function renderPage(array $blocks): string
	{
		if (empty($blocks))
		{
			return "\n";
		}

		$parts = [];
		foreach ($blocks as $block)
		{
			$md = $this->renderBlock($block, (string)($block['CODE'] ?? ''));

			if ($md !== '')
			{
				$parts[] = $md;
			}
		}

		$parts = self::collapseDividers($parts);
		if (empty($parts))
		{
			return "\n";
		}

		return implode("\n\n", $parts) . "\n";
	}

	/**
	 * A divider only means anything between two pieces of content, and landing pages stack several
	 * of them in a row and open or close a page with one. A document that starts with a horizontal
	 * line - or holds nothing else - reads as broken, so leading, trailing and repeated dividers go.
	 *
	 * @param string[] $parts
	 * @return string[]
	 */
	private static function collapseDividers(array $parts): array
	{
		$kept = [];
		foreach ($parts as $md)
		{
			if ($md === self::HORIZONTAL_RULE && ($kept === [] || end($kept) === self::HORIZONTAL_RULE))
			{
				continue;
			}

			$kept[] = $md;
		}

		if ($kept !== [] && end($kept) === self::HORIZONTAL_RULE)
		{
			array_pop($kept);
		}

		return $kept;
	}

	/**
	 * Converts a single block, retrying through the fallback converter when the chosen converter
	 * yields nothing for a block that does carry content (SDD ALG-02). A specialized converter whose
	 * selectors miss the block's markup used to drop it from the page without a trace, and so did
	 * every converter exception; the retry is logged so a silent loss cannot turn into a silent
	 * degradation either.
	 *
	 * A decorative divider is answered before all of it: it has nothing to convert and nothing to
	 * recover, and the visual break it stands for is carried over as a horizontal rule (P7.T2).
	 */
	private function renderBlock(array $block, string $code): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		if (self::isDecorativeDivider($content))
		{
			return self::HORIZONTAL_RULE;
		}

		$converter = $this->resolveConverter($code, $content);
		$md = $this->convertQuietly($converter, $block);

		if ($md !== '' || !self::hasRenderableContent($content))
		{
			return $md;
		}

		$md = $this->convertQuietly($this->fallback, $block);
		ImportLogger::logInfo(sprintf(
			'landing import: empty conversion, code=%s, converter=%s, fallback=%s',
			$code,
			$converter::class,
			$md === '' ? 'empty' : 'recovered',
		));

		return $md;
	}

	/**
	 * A block that makes its converter fault must not take the whole page down; the empty result
	 * routes into the ALG-02 retry, same as a converter that simply extracted nothing.
	 */
	private function convertQuietly(Converter $converter, array $block): string
	{
		try
		{
			return trim($converter->convert($block));
		}
		catch (\Throwable)
		{
			return '';
		}
	}

	/**
	 * ALG-02 criterion for "the source block was not empty": any text, or any media element the
	 * converters carry over. Decorative blocks (spacers) stay empty and are dropped without a retry;
	 * a divider never reaches the net, being answered by {@see isDecorativeDivider()} before it.
	 *
	 * Script and style bodies are cut out before BOTH checks. `strip_tags` keeps their text, so a
	 * block that holds nothing but its own styles would count as non-empty; and markup inside a
	 * script body is that script's code, not content of the block. Either way the block would be run
	 * through the fallback for nothing and leave a loss in the import log that never happened.
	 */
	private static function hasRenderableContent(string $content): bool
	{
		$visible = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $content) ?? $content;
		if (trim(strip_tags($visible)) !== '')
		{
			return true;
		}

		foreach (self::MEDIA_MARKERS as $marker)
		{
			if (stripos($visible, $marker) !== false)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A block that draws a divider and holds nothing besides it. The eight `26.*separator*` templates
	 * spell the divider either as an <hr> or as a full-width <svg> shape, so it is read from the
	 * markup and not from the codes, which live in the release cycle of landing.
	 *
	 * Answered before the ALG-02 safety net on purpose: a divider is the one block whose empty
	 * conversion is legitimate, and the net would otherwise keep reporting these as losses.
	 */
	private static function isDecorativeDivider(string $content): bool
	{
		if (stripos($content, self::DIVIDER_CLASS) === false && preg_match('#<hr[\s/>]#i', $content) !== 1)
		{
			return false;
		}

		return !self::hasRenderableContent($content);
	}

	/**
	 * A block that IS a call-to-action button: outside the button node it carries neither text nor
	 * media. The button converter renders the button alone, so a block that also has a heading and a
	 * paragraph must not reach it - those would be dropped, while the fallback carries them over.
	 */
	private static function isButtonOnly(string $content): bool
	{
		if (stripos($content, self::BUTTON_CLASS) === false)
		{
			return false;
		}

		return !self::hasRenderableContent(BlockHtmlExtractor::stripNodes($content, [self::BUTTON_CLASS]));
	}

	private static function hasTableMarkup(string $content): bool
	{
		return stripos($content, '<table') !== false;
	}

	public function resolveConverter(string $code, string $content = ''): Converter
	{
		if ($code === self::TEXT_TABLE_CODE)
		{
			return self::hasTableMarkup($content)
				? $this->tableConverter
				: $this->textConverter;
		}

		if (isset($this->explicit[$code]))
		{
			return $this->explicit[$code];
		}

		// Card collections are detected by markup, not CODE (the same card layout ships under many
		// codes). Checked before the generic regex routing so slider slides are recovered
		// sequentially and static card lists become tables - otherwise they land on Gallery/Fallback
		// and lose their content or duplicate retina images. Each converter answers for its own
		// markup, so a card list it cannot read (the FAQ blocks keep question and answer in classes
		// of their own) keeps choosing from the rules below instead of rendering an empty table.
		if ($content !== '')
		{
			if (SliderConverter::matches($content))
			{
				return $this->sliderConverter;
			}

			// A question-answer pair is a card too, but it is described by its own markup - so the
			// more specific rule goes first, or the pairs of a block that also fills the card
			// selectors (68.6) end up glued together in one table cell.
			if (FaqConverter::matches($content))
			{
				return $this->faqConverter;
			}

			if (CardTableConverter::matches($content))
			{
				return $this->cardTableConverter;
			}
		}

		// A table is routed by its markup as well: the five table blocks of landing live in its own
		// release cycle, and there is no route to the table converter by CODE at all. This has to
		// stay ahead of the CODE patterns below, or a pattern that merely looks similar picks the
		// block up again (P4.T1).
		if (self::hasTableMarkup($content))
		{
			return $this->tableConverter;
		}

		// P2.T6 regex extensions are layered on by registering converters via
		// {@see registerExtendedConverter} during DI wiring; see SDD ALG-02.
		foreach ($this->regexConverters as $entry)
		{
			if (preg_match($entry['pattern'], $code))
			{
				return $entry['converter'];
			}
		}

		// The button converter is reachable by markup only: no landing block carries a `button`/`cta`
		// CODE, so the pattern above never fires and the block came out as the bare text of its button,
		// link and all. The check stands last of the specialized rules - a card list, a slider or a
		// column layout with a button inside stays what it is (P7.T1).
		if (self::isButtonOnly($content))
		{
			return $this->buttonConverter;
		}

		return $this->fallback;
	}

	/** @var array<int, array{pattern:string, converter:Converter}> */
	private array $regexConverters = [];

	public function registerExtendedConverter(string $pattern, Converter $converter): void
	{
		$this->regexConverters[] = [
			'pattern' => $pattern,
			'converter' => $converter,
		];
	}

	/**
	 * Set of block codes the dispatcher knows about. Exposed for the routing table tests,
	 * which assert coverage and that no route points at a removed converter.
	 *
	 * @return string[]
	 */
	public function knownExplicitCodes(): array
	{
		return array_merge(array_keys($this->explicit), [self::TEXT_TABLE_CODE]);
	}

	/**
	 * @return string[] regex patterns registered via {@see registerExtendedConverter}.
	 */
	public function knownRegexPatterns(): array
	{
		return array_map(static fn(array $e): string => $e['pattern'], $this->regexConverters);
	}
}
