<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockMediaContext;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Restores landing slider/carousel blocks whose slides are `.landing-block-card.js-slide` cards
 * (image + title + caption + optional link) - a layout the note editor cannot reproduce, so the
 * slides are flattened into the document sequentially instead of being dropped.
 *
 * Slide images are usually CSS `background-image` divs carrying `data-fileid`, so they are read
 * from that attribute (GalleryConverter only sees real `<img>` tags and would emit nothing).
 * Each slide contributes, in order: the image, its title, its caption, and its pseudo-url link if
 * enabled.
 *
 * The output opens with what the block says outside the carousel - its heading, its intro text, a
 * lead image - followed by the section-level media and then the slides. Section media is a "cover"
 * the carousel plays over: a background video (`.landing-block-node-video`) or an embed
 * (`.landing-block-node-embed`); a slide may carry a video of its own instead of an image
 * (`.landing-block-node-card-video`), and then it is emitted inside that slide. Videos are recovered
 * as a bare URL for whitelisted hosts and through the one link rule otherwise, so a slider carrying
 * video is not lost either. Note has no decorative "background video" node, so the video is surfaced
 * as normal media in the document flow.
 */
final class SliderConverter implements Converter
{
	/** Title node classes across slider variants, most specific first. */
	private const TITLE_SELECTORS = ['landing-block-node-card-title', 'landing-block-node-title'];
	/** Caption node classes across slider variants, most specific first. */
	private const CAPTION_SELECTORS = ['landing-block-card-text', 'landing-block-node-card-text', 'landing-block-node-text'];
	/** Embed wrapper class: the only marker whose URL may sit on a nested iframe/anchor instead. */
	private const EMBED_SELECTOR = 'landing-block-node-embed';
	/** Section media this converter emits itself, so the lead content must not repeat it. */
	private const MEDIA_SELECTORS = ['landing-block-node-video', self::EMBED_SELECTOR];
	/** Slide media: `49.4.two_cols_video_carousel` fills every slide with nothing but a video. */
	private const SLIDE_MEDIA_SELECTORS = ['landing-block-node-card-video', 'landing-block-node-video', self::EMBED_SELECTOR];
	/** Video URL attributes of a media marker, in preference order. */
	private const URL_ATTRIBUTES = ['data-source', 'data-src', 'src'];

	public function __construct(
		private readonly HtmlToMarkdown $html = new HtmlToMarkdown(),
		private readonly FileReader $files = new FileReader(),
	)
	{
	}

	/**
	 * True when the content has at least one slider slide (`.landing-block-card.js-slide`).
	 */
	public static function matches(string $content): bool
	{
		foreach (BlockHtmlExtractor::extractCardNodes($content) as $card)
		{
			if ($card['slide'])
			{
				return true;
			}
		}

		return false;
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');

		$parts = [];

		// Section background video / embeds come before the slides: the carousel plays over them (a
		// "cover"), so they read as the lead media once the layout is flattened into the document.
		// Only what lies outside the slides counts as section media - a video of a slide belongs to
		// that slide and is emitted there, otherwise it would be torn off its caption.
		$outsideSlides = BlockHtmlExtractor::stripNodes($content, BlockHtmlExtractor::CARD_CLASSES);
		foreach ($this->videoLines($outsideSlides, self::MEDIA_SELECTORS) as $video)
		{
			$parts[] = $video;
		}

		foreach (BlockHtmlExtractor::extractCardNodes($content) as $card)
		{
			if (!$card['slide'])
			{
				continue;
			}

			$videos = $this->videoLines($card['html'], self::SLIDE_MEDIA_SELECTORS);
			$link = $this->slideLink($card['html']);
			if (in_array($link, $videos, true))
			{
				// A play button pointing at the video the slide already carries: one video, one line.
				$link = '';
			}

			$slide = array_filter([
				implode("\n\n", $videos),
				$this->slideImage($card['html']),
				$this->slideTitle($card['html']),
				$this->slideCaption($card['html']),
				$link,
			], static fn(string $piece): bool => $piece !== '');

			if ($slide !== [])
			{
				$parts[] = implode("\n\n", $slide);
			}
		}

		if ($parts === [])
		{
			// Nothing of the carousel was readable: stay empty so the dispatcher can retry the whole
			// block through the fallback (ALG-02) instead of leaving only its heading behind.
			return '';
		}

		$lead = $this->leadMarkdown($block, $content);

		return $lead === '' ? implode("\n\n", $parts) : $lead . "\n\n" . implode("\n\n", $parts);
	}

	/**
	 * Markdown of what the block carries outside the carousel - its heading, its intro text, a lead
	 * image. The slides and the section media are cut out: both are rendered by this converter
	 * itself, so keeping them here would duplicate them.
	 */
	private function leadMarkdown(array $block, string $content): string
	{
		$blockId = (int)($block['ID'] ?? 0);
		$media = BlockMediaContext::forContent(
			$blockId > 0 ? $this->files->filesForBlock($blockId) : [],
			$content,
		);

		$lead = BlockHtmlExtractor::stripNodes(
			$content,
			array_merge(BlockHtmlExtractor::CARD_CLASSES, self::MEDIA_SELECTORS),
		);

		return trim($this->html->convert($lead, $media));
	}

	private function slideTitle(string $slideHtml): string
	{
		$title = $this->firstText($slideHtml, self::TITLE_SELECTORS);

		return $title === '' ? '' : '**' . $title . '**';
	}

	private function slideImage(string $slideHtml): string
	{
		$fileId = BlockHtmlExtractor::firstFileId($slideHtml);
		if ($fileId !== null)
		{
			return '![](' . LandingSource::ATTACHMENT_SCHEME . $fileId . ')';
		}

		$src = BlockHtmlExtractor::extractImageDescriptors($slideHtml)[0]['src'] ?? '';
		if ($src === '')
		{
			return '';
		}

		return '![](' . (LandingSource::externalAttachmentUrl($src) ?? $src) . ')';
	}

	private function slideCaption(string $slideHtml): string
	{
		return $this->firstText($slideHtml, self::CAPTION_SELECTORS);
	}

	/**
	 * Plain text of the first non-empty node among $selectors (tags stripped, whitespace collapsed).
	 *
	 * @param string[] $selectors
	 */
	private function firstText(string $slideHtml, array $selectors): string
	{
		foreach ($selectors as $selector)
		{
			$html = BlockHtmlExtractor::extractBySelector($slideHtml, $selector);
			if ($html === '')
			{
				continue;
			}
			$text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			$text = (string)preg_replace('/\s+/u', ' ', $text);
			if ($text !== '')
			{
				// Escape markdown metacharacters: this is page prose that gets wrapped in markdown
				// structure (bold title / caption paragraph), so it must not inject its own markup.
				return BlockHtmlExtractor::escapeMarkdownInline($text);
			}
		}

		return '';
	}

	private function slideLink(string $slideHtml): string
	{
		$pseudo = BlockHtmlExtractor::pseudoUrl($slideHtml);
		if ($pseudo['href'] !== '')
		{
			return $this->linkMarkdown($pseudo['href'], $pseudo['text']);
		}

		$link = BlockHtmlExtractor::firstLink($slideHtml);
		if ($link['href'] === '')
		{
			return '';
		}

		return $this->linkMarkdown($link['href'], $link['text']);
	}

	/**
	 * A slide link as markdown, or a bare URL when the link carries no label of its own and points at
	 * a whitelisted video host: the label would then only repeat the address, while the bare form is
	 * what the editor turns into a player. That is how `48.slider_with_video_on_bgimg` keeps its video
	 * - there the address sits on the slide's play button, not on a video node. A link with a label of
	 * its own stays a link wherever the document may carry one; a contact address and a scheme the
	 * editor drops keep their text instead ({@see HtmlToMarkdown::linkOrText()}).
	 *
	 * An unresolved landing substitution reaches neither half: this converter builds the link out of
	 * extracted attributes instead of walking the markup, so - like {@see CardTableConverter::cardLink()}
	 * and {@see ButtonConverter} - it drops the mask by the one rule of HtmlToMarkdown rather than a
	 * spelling of its own (P7.T8). A bare `#` fragment is left alone: that one is a jump the page really
	 * wrote and is downgraded downstream.
	 */
	private function linkMarkdown(string $href, string $label): string
	{
		$label = trim(HtmlToMarkdown::dropSubstitutions($label));
		if (HtmlToMarkdown::isSubstitutionHref($href))
		{
			$href = '';
		}
		if ($href === '' && $label === '')
		{
			return '';
		}

		if ($label === '' && VideoConverter::isEmbedWhitelisted($href))
		{
			return $href;
		}

		// The label is escaped as the markdown text it becomes; the href is left to the link rule,
		// which escapes what would end the link target early.
		$text = BlockHtmlExtractor::escapeMarkdownInline($label !== '' ? $label : $href);
		if ($href === '')
		{
			return $text;
		}

		return HtmlToMarkdown::linkOrText($text, $href);
	}

	/**
	 * Videos/embeds marked up in $html by any of $cssClasses, in document order, de-duplicated.
	 *
	 * A whitelisted video host (YouTube/Vimeo/RuTube/VK) is emitted as a bare URL, so the note editor
	 * recognizes it and builds a player; anything else goes out by the one link rule
	 * ({@see HtmlToMarkdown::linkOrText()}) - a link where the document may carry one, the address
	 * itself where it may not - so the source URL survives rather than being dropped. A marker whose
	 * whole address is an unresolved landing substitution names no video and goes with the line: there
	 * is nothing to play and nothing to label it with.
	 *
	 * @param string[] $cssClasses
	 *
	 * @return string[]
	 */
	private function videoLines(string $html, array $cssClasses): array
	{
		$lines = [];

		foreach ($cssClasses as $cssClass)
		{
			foreach ($this->markerUrls($html, $cssClass) as $src)
			{
				$src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				if ($src === '' || HtmlToMarkdown::isSubstitutionHref($src))
				{
					continue;
				}

				$lines[] = VideoConverter::isEmbedWhitelisted($src)
					? $src
					: HtmlToMarkdown::linkOrText(BlockHtmlExtractor::escapeMarkdownInline($src), $src);
			}
		}

		return array_values(array_unique($lines));
	}

	/**
	 * Video URL of every $cssClass marker in $html, in document order (an empty string for a marker
	 * that keeps no URL at all).
	 *
	 * The canonical page URL (data-source) wins over the embed URL (data-src) and over the iframe src:
	 * it is clean (no HTML-entity-encoded player query) and the editor turns it into a player, while an
	 * embed URL of Vimeo/RuTube/VK stays plain text. Landing writes both attributes side by side on the
	 * node (`Node\Embed::saveNode()`), so the choice is ours to make.
	 *
	 * @return string[]
	 */
	private function markerUrls(string $html, string $cssClass): array
	{
		// Same XPath in both helpers, so the inner html of marker #i belongs to attribute set #i.
		$markers = BlockHtmlExtractor::extractElementAttributes($html, $cssClass, self::URL_ATTRIBUTES);
		$inner = $cssClass === self::EMBED_SELECTOR
			? BlockHtmlExtractor::extractAllBySelector($html, $cssClass)
			: [];

		$urls = [];
		foreach ($markers as $index => $attributes)
		{
			$url = '';
			foreach (self::URL_ATTRIBUTES as $attribute)
			{
				if (($attributes[$attribute] ?? '') !== '')
				{
					$url = (string)$attributes[$attribute];
					break;
				}
			}

			if ($url === '' && isset($inner[$index]))
			{
				// Legacy embed markup: the wrapper carries no URL, the player iframe inside it does.
				$url = self::embeddedUrl($inner[$index]);
			}

			$urls[] = $url;
		}

		return $urls;
	}

	private static function embeddedUrl(string $embedHtml): string
	{
		$src = BlockHtmlExtractor::extractFirstAttribute($embedHtml, 'iframe', 'src');

		return $src === '' ? BlockHtmlExtractor::extractFirstAttribute($embedHtml, 'a', 'href') : $src;
	}
}
