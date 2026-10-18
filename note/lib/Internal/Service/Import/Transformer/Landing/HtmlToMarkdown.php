<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing;

use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\BlockHtmlExtractor;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter\VideoConverter;

/**
 * Converts a snippet of landing block HTML to note-compatible markdown.
 *
 * - <h1>-<h4> -> '#'..'####'
 * - <h5>, <h6> -> '#### **bold text**' (Q-3 default - note editor caps headings at H4)
 * - a landing title/subtitle class on a block element -> heading of the same level the landing
 *   templates give that role to a heading tag (see CLASS_HEADING_LEVELS)
 * - <p> -> paragraph with surrounding blank lines
 * - <ul>/<li>, <ol>/<li> -> markdown list (nested lists indented by 2 spaces per level)
 * - <table> -> markdown table via {@see TableRenderer}; a table nested in a table cell or a list
 *   item degrades to the text of its cells (markdown cannot express the nesting)
 * - <a href> -> [text](href) for a target the editor keeps (http/https) and for the landing bindings
 *   the import resolves later; a `mailto:`/`tel:` contact, which the editor would strip to its label,
 *   leaves its address in the text instead, and any other scheme leaves the label alone. A picture
 *   wrapped in a link steps out of the label and stands before it: a note document has no form for a
 *   picture that is also a link, and one left in the label breaks both
 * - <strong>/<b> -> **text**; <em>/<i> -> *text*; <s>/<del> -> ~~text~~; <code> -> `text`
 * - <br> -> soft line break (two trailing spaces, preserved by whitespace normalization)
 * - text of a text node -> escaped, so prose spelled with a markdown character (a leading asterisk,
 *   an identifier with underscores, a hash, a pipe) stays prose instead of turning into a list,
 *   italics, a heading or a broken table row
 * - a landing substitution (`#crmPhoneTitle1`, `#system_mainpage`, `#title#`) -> dropped, whether it
 *   stands as text, as a link label or as a link target (see SUBSTITUTION_PATTERN)
 * - <img> -> ![alt](src); when a BlockMediaContext is supplied the src is resolved to the
 *   block's next landing FILE_ID as `landing-file://N?w=..&a=..` so the downloader can
 *   copy the file and the editor can restore width/align (matches ImageConverter output)
 * - <iframe> -> whitelisted embed hosts become a standalone URL line (video embed); other iframes
 *   degrade to a markdown link, or to the address as text when the editor would not keep the link,
 *   so the source is not lost either way
 * - <script>, <style> and <?php ... ?> bodies and all bx-* / landing-* / data-* / on* attributes
 *   are stripped
 */
final class HtmlToMarkdown
{
	private const MAX_HEADING_LEVEL = 4;
	/**
	 * Landing marks a block's title and its kicker/subtitle line with a class, and 26 delivery
	 * templates put that class on a plain element instead of a heading tag. The levels mirror the
	 * tags the other templates use for the same role (`<h2 class="landing-block-node-title">` is by
	 * far the most common spelling), so both spellings of one role import identically.
	 */
	private const CLASS_HEADING_LEVELS = [
		'landing-block-node-title' => 2,
		'landing-block-node-subtitle' => 3,
	];
	/**
	 * Only a block element takes a heading from its class. The same classes also sit on inline runs
	 * that are not titles at all - the site name of the store menu blocks and the "go back" caption
	 * are `<a class="landing-block-node-title">` - and those must stay links.
	 */
	private const CLASS_HEADING_TAGS = ['div', 'p', 'section', 'article', 'header', 'aside', 'main'];
	/** A class-marked node holding these is a wrapper around content, not a heading itself. */
	private const BLOCK_CHILD_TAGS = [
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'p', 'div', 'section', 'article', 'figure', 'blockquote', 'pre', 'hr',
		'ul', 'ol', 'table', 'img', 'iframe',
	];
	/** Longer than a title: the class sits on a paragraph, so it stays a paragraph. */
	private const MAX_CLASS_HEADING_LENGTH = 120;
	/** Characters markdown reads as markup wherever they stand in a line. */
	private const INLINE_MARKUP_PATTERN = '/[\\\\`*_\[\]|~]/u';
	/**
	 * Landing substitutions, in the spelling the saved block content holds. Landing fills them in while
	 * it renders a page - the CRM bindings of the contact blocks (`Landing::view()`), the system-page
	 * targets of the menu blocks and its own meta markers - and the import reads what is *saved*, so
	 * every one of them arrives here as its literal mask. None may reach the reader: `#crmPhoneTitle1`
	 * in place of a phone number is not a phone number. Filling them in instead of dropping them is a
	 * separate question - CRM data has access rules of its own - and is deliberately not answered here.
	 */
	private const SUBSTITUTION_PATTERN = '/#(?:crm|requisite|system_)[a-z0-9_]*|#(?:title|breadcrumb|content|year)#/i';
	/**
	 * A bare address written in the prose. Markdown reads nothing inside it as markup, while the editor
	 * does turn it into a link, so escaping its underscores and tildes would only break the address.
	 */
	private const BARE_URL_PATTERN = '~((?:https?://|www\.)[^\s<>()\[\]]+)~i';
	/** A contact target: the address of one is kept as text, see {@see contactAddress()}. */
	private const CONTACT_HREF_PATTERN = '~^(?:mailto|tel):~i';
	/** A target written with a scheme, whatever that scheme is - see {@see isLinkableHref()}. */
	private const HREF_SCHEME_PATTERN = '~^[a-z][a-z0-9+.\-]*:~i';
	/** The schemes a link may carry into the document, see {@see isLinkableHref()}. */
	private const LINKABLE_HREF_PATTERN = '~^(?:https?://|(?:page|block|user|form|file):#)~i';
	/**
	 * An image as this conversion writes one ({@see renderImage()} and the block converters). Prose
	 * cannot be mistaken for one: a `[` of the page is escaped by then, and `!\[` is not `![`.
	 */
	private const IMAGE_MARKDOWN_PATTERN = '/!\[(?P<alt>[^\]\n]*)\]\([^)\n]*\)/';

	/** Nesting depth of the table currently being rendered - see {@see renderTable()}. */
	private int $tableDepth = 0;
	/** Nesting depth of the label of a link that points somewhere real - see {@see renderLink()}. */
	private int $liveLinkDepth = 0;

	public function convert(string $html, ?BlockMediaContext $media = null): string
	{
		if ($html === '')
		{
			return '';
		}

		$html = $this->stripDangerous($html);
		$dom = $this->loadDom($html);
		if ($dom === null)
		{
			return $this->fallbackText($html);
		}

		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body === null)
		{
			return $this->fallbackText($html);
		}

		$markdown = $this->renderNodes($body->childNodes, 0, $media);

		return $this->normalizeWhitespace($markdown);
	}

	/**
	 * Converts markup that lands inside a table cell. A cell is one line, so the result is folded by
	 * the cell rule of {@see TableRenderer::flattenCell()}, and a table in the markup degrades to the
	 * text of its cells - exactly as a table nested in a table cell already does, instead of leaving
	 * pipe rows and a delimiter row inside the cell it was put in.
	 */
	public function convertCell(string $html, ?BlockMediaContext $media = null): string
	{
		$this->tableDepth++;
		try
		{
			return TableRenderer::flattenCell($this->convert($html, $media));
		}
		finally
		{
			$this->tableDepth--;
		}
	}

	private function stripDangerous(string $html): string
	{
		/*
		 * Component-backed delivery templates keep their PHP in the content - a
		 * `<?php $APPLICATION->IncludeComponent(...) ?>` call, short `<?` and `<?=` opening tags
		 * included. The markup walk has no notion of it and would carry the code into the document as
		 * prose, so it goes the same way as a script or a style body. An unterminated opening tag runs
		 * to the end of the content, as it does in PHP itself.
		 */
		$html = preg_replace('#<\?(?:php|=)?.*?(?:\?>|\z)#is', '', $html) ?? $html;
		$html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
		$html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? $html;
		// Drop on*-attributes; keep core href/src/alt/title intact.
		$html = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#is', '', $html) ?? $html;
		$html = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#is", '', $html) ?? $html;

		return $html;
	}

	private function loadDom(string $html): ?\DOMDocument
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

	private function renderNodes(\DOMNodeList $nodes, int $listDepth = 0, ?BlockMediaContext $media = null): string
	{
		$out = '';
		foreach ($nodes as $node)
		{
			$out .= $this->renderNode($node, $listDepth, $media);
		}

		return $out;
	}

	private function renderNode(\DOMNode $node, int $listDepth, ?BlockMediaContext $media = null): string
	{
		if ($node->nodeType === XML_TEXT_NODE)
		{
			return $this->escapeInline($node->nodeValue ?? '');
		}

		if ($node->nodeType !== XML_ELEMENT_NODE)
		{
			return '';
		}

		/** @var \DOMElement $node */
		$tag = strtolower($node->nodeName);

		// Landing background/embed videos are marker divs (`.landing-block-node-video`) carrying the
		// URL on data-source/data-src, not an <iframe>. Handle them before the generic element walk
		// so the video is not lost outside a slider (MultiColumn / Fallback / ... all route through here).
		if ($this->hasCssClass($node, 'landing-block-node-video'))
		{
			return $this->renderVideoNode($node);
		}

		// Inline CRM-form embeds are marker divs (`<div data-b24form="#crmFormInline<id>">`) whose only
		// body is a "форма появится здесь" placeholder. note cannot host a live CRM form, so replace the
		// marker with a link to the form's public page (LandingSource resolves the `form:#...` scheme, or
		// drops it to text when CRM/the form is unavailable) - handled before the generic element walk.
		if ($node->hasAttribute('data-b24form'))
		{
			return $this->renderFormEmbed($node);
		}

		// A title marked by class, not by tag. Returns null when the node turns out to carry
		// something other than a title, and the element is then rendered by the walk below.
		$classHeading = $this->renderClassHeading($node, $media);
		if ($classHeading !== null)
		{
			return $classHeading;
		}

		switch ($tag)
		{
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
				$inner = $this->headingText($node, $media);
				// Skip blank headings (a designer left an empty title placeholder) - like <p>, an
				// empty heading must not leave a bare `##` marker behind. A heading whose whole text
				// was an unresolved binding (`#crmCompanyTitle`) arrives blank here for the same reason.
				if ($this->isBlank($inner))
				{
					return '';
				}
				return "\n\n" . str_repeat('#', (int)$tag[1]) . ' ' . $this->wrapColor($node, $inner) . "\n\n";

			case 'h5':
			case 'h6':
				$inner = $this->headingText($node, $media);
				if ($this->isBlank($inner))
				{
					return '';
				}
				return "\n\n" . str_repeat('#', self::MAX_HEADING_LEVEL) . ' ' . $this->wrapColor($node, '**' . $inner . '**') . "\n\n";

			case 'p':
				$inner = trim($this->renderInline($node, $media));
				if ($inner === '')
				{
					return '';
				}
				return "\n\n" . $this->wrapColor($node, $inner) . "\n\n";

			case 'br':
				return "  \n";

			case 'hr':
				return "\n\n---\n\n";

			case 'blockquote':
				return $this->renderBlockquote($node, $listDepth, $media);

			case 'pre':
				return $this->renderPre($node);

			case 'ul':
				return $this->renderList($node, $listDepth, false, $media);

			case 'ol':
				return $this->renderList($node, $listDepth, true, $media);

			case 'table':
				return $this->renderTable($node, $listDepth, $media);

			case 'a':
				return $this->renderLink($node, $media);

			case 'strong':
			case 'b':
				$inner = trim($this->renderInline($node, $media));
				return $inner === '' ? '' : '**' . $inner . '**';

			case 'em':
			case 'i':
				$inner = trim($this->renderInline($node, $media));
				return $inner === '' ? '' : '*' . $inner . '*';

			case 's':
			case 'del':
			case 'strike':
				$inner = trim($this->renderInline($node, $media));
				return $inner === '' ? '' : '~~' . $inner . '~~';

			case 'code':
				$inner = $this->getRawText($node);
				return $inner === '' ? '' : '`' . str_replace('`', "\u{200B}`", $inner) . '`';

			case 'img':
				return $this->renderImage($node, $media);

			case 'iframe':
				return $this->renderIframe($node);

			case 'span':
			case 'font':
				// Inline runs with an explicit color (`<span style="color:#fe541e">`, `<font color="...">`)
				// carry a text color the user set by hand in landing; wrap the inner markdown in a
				// `{color:..}` token when a safe explicit color is present, otherwise just flatten.
				return $this->wrapColor($node, $this->renderNodes($node->childNodes, $listDepth, $media));

			case 'div':
			case 'section':
			case 'article':
			case 'header':
			case 'footer':
			case 'main':
			case 'nav':
			case 'aside':
			case 'figure':
			case 'figcaption':
				return $this->renderNodes($node->childNodes, $listDepth, $media);

			default:
				return $this->renderNodes($node->childNodes, $listDepth, $media);
		}
	}

	private function renderInline(\DOMNode $node, ?BlockMediaContext $media = null): string
	{
		$out = '';
		foreach ($node->childNodes as $child)
		{
			$out .= $this->renderNode($child, 0, $media);
		}

		return $out;
	}

	/**
	 * Inline content of a heading, folded onto a single line. A markdown heading ends at the line
	 * break, so a break inside it would leave the tail of the title behind as a paragraph - and
	 * landing templates wrap a long title across lines and put a `<br>` in it by hand.
	 */
	private function headingText(\DOMElement $node, ?BlockMediaContext $media): string
	{
		$inner = $this->renderInline($node, $media);

		return trim(preg_replace('/\s*\R\s*/u', ' ', $inner) ?? $inner);
	}

	/**
	 * A landing title/subtitle marked by class instead of a heading tag ({@see CLASS_HEADING_LEVELS}).
	 * Returns the heading markdown, an empty string for a blank/unresolved title, and null when the
	 * node is no title of its own - an inline run, a wrapper around real content, or a paragraph too
	 * long to be a title - so the caller keeps rendering it as the element it is. A heading tag is
	 * never overridden here: the tag is the more precise statement of the same role.
	 */
	private function renderClassHeading(\DOMElement $node, ?BlockMediaContext $media): ?string
	{
		if (!in_array(strtolower($node->nodeName), self::CLASS_HEADING_TAGS, true))
		{
			return null;
		}

		$level = null;
		foreach (self::CLASS_HEADING_LEVELS as $class => $headingLevel)
		{
			if ($this->hasCssClass($node, $class))
			{
				$level = $headingLevel;
				break;
			}
		}
		if ($level === null || $this->hasBlockChild($node))
		{
			return null;
		}

		$inner = trim($this->renderInline($node, $media));
		if ($this->isBlank($inner))
		{
			return '';
		}
		if (str_contains($inner, "\n") || mb_strlen($inner) > self::MAX_CLASS_HEADING_LENGTH)
		{
			return null;
		}

		$level = min($level, self::MAX_HEADING_LEVEL);

		return "\n\n" . str_repeat('#', $level) . ' ' . $this->wrapColor($node, $inner) . "\n\n";
	}

	private function hasBlockChild(\DOMElement $node): bool
	{
		foreach ($node->childNodes as $child)
		{
			if ($child instanceof \DOMElement && in_array(strtolower($child->nodeName), self::BLOCK_CHILD_TAGS, true))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Renders a table through the shared {@see TableRenderer}. Markdown has no form for a table
	 * inside a table cell or a list item, so the nesting degrades to the cell texts instead of
	 * emitting pipe rows that would break the row or the list around them.
	 */
	private function renderTable(\DOMElement $node, int $listDepth, ?BlockMediaContext $media): string
	{
		$nested = $listDepth > 0 || $this->tableDepth > 0;
		$renderCell = fn(\DOMElement $cell): string => $this->renderNodes($cell->childNodes, 0, $media);

		$this->tableDepth++;
		try
		{
			if ($nested)
			{
				$flat = TableRenderer::flatten($node, $renderCell);

				// Spaced out: the cell text around it is inline, so without the padding the first
				// cell of the nested table glues onto the word before the table.
				return $flat === '' ? '' : ' ' . $flat . ' ';
			}

			$table = TableRenderer::render($node, $renderCell);

			return $table === '' ? '' : "\n\n" . $table . "\n\n";
		}
		finally
		{
			$this->tableDepth--;
		}
	}

	/**
	 * True when the text is empty or only whitespace - including Unicode separators such as the
	 * `&nbsp;` (U+00A0) that PHP's trim() leaves in place. Used to drop blank headings.
	 */
	private function isBlank(string $text): bool
	{
		return $text === '' || preg_replace('/[\pZ\s]+/u', '', $text) === '';
	}

	private function renderLink(\DOMElement $node, ?BlockMediaContext $media = null): string
	{
		$href = trim($node->getAttribute('href'));
		$dead = $this->isDeadHref($href);
		$text = trim($dead ? $this->renderInline($node, $media) : $this->renderLiveLinkLabel($node, $media));

		// Dead target: empty / bare `#` / an unresolved landing binding (`tel:#crmPhone1`,
		// `#system_mainpage`). Empty social icons, unbound phone/email buttons and the store menus carry
		// these - keep what is left of the label as plain (colored) text, emitting nothing when the label
		// was a mask of its own, but never a link that points nowhere.
		if ($dead)
		{
			return $this->wrapColor($node, $text);
		}

		return $this->wrapColor($node, self::linkOrText($text, $href));
	}

	/**
	 * The markdown of a target that is not dead, written against a label the caller has already made
	 * safe: an unresolved landing substitution is no target and leaves the label alone
	 * ({@see isSubstitutionHref()}), a `mailto:`/`tel:` contact leaves its address in the text
	 * ({@see contactAddress()}), a scheme the document may not carry leaves the words of the label
	 * alone ({@see isLinkableHref()}), and everything else becomes a link whose destination survives
	 * the markdown ({@see markdownSafeUrl()}) and which, left with no label, is spelled by its own
	 * address. A picture never stays in the label ({@see takeImagesOutOfLabel()}).
	 *
	 * Public for the same reason as {@see isSubstitutionHref()}: the converters that assemble a link
	 * out of extracted attributes ({@see BlockConverter\ButtonConverter},
	 * {@see BlockConverter\CardTableConverter::cardLink()}) answer the same question, and a link the
	 * reader can follow must not depend on which of them built it. This is also the one place a text
	 * becomes a label, so the label rule is applied here rather than in each of them.
	 */
	public static function linkOrText(string $label, string $href): string
	{
		// A landing substitution names no target and never will, so there is no link to write. The
		// converters that build a link out of extracted attributes answer this before they get here,
		// each having a fallback of its own to choose; for everyone else the rule stands here, and it
		// has to stand ahead of the contact rule below - `tel:#crmPhone1` reaching that one would put
		// the mask where the phone number belongs (`**Позвонить** (#crmPhone1)`).
		if (self::isSubstitutionHref($href))
		{
			return $label;
		}

		$contact = self::contactAddress($href);
		if ($contact !== null)
		{
			return self::contactText($label, $contact);
		}

		if (!self::isLinkableHref($href))
		{
			return $label;
		}

		[$images, $label] = self::takeImagesOutOfLabel($label);

		// Only past the answers above does the text become a label: they hand it back as prose,
		// where a bracket is a character like any other and keeps the escape the reader is shown by.
		$label = BlockHtmlExtractor::dropLabelBrackets($label);
		if ($label === '' && $images !== '')
		{
			// A picture with nothing to name it by: the address has no label to travel on, and the
			// picture is what the link was put there to show.
			return $images;
		}

		$link = '[' . ($label === '' ? $href : $label) . '](' . self::markdownSafeUrl($href) . ')';

		return $images === '' ? $link : $images . ' ' . $link;
	}

	/**
	 * The pictures of a label, taken out of it: the markdown of the images in the order they stood,
	 * and what is left of the label beside them.
	 *
	 * A picture cannot stand between the brackets of a label. The attachment chain writes the block
	 * token `[[image fileId=N]]` in its place and lifts that token onto a line of its own
	 * ({@see \Bitrix\Note\Internal\Service\Import\Transformer\ImportMdTransformer}), which tears the
	 * link in two and leaves a `](href)` tail in the text; and every reader of a label matches one as
	 * "everything up to the first `]`", so the target of such a link is never resolved either - a
	 * landing binding would reach an editor that rejects it.
	 *
	 * A label left with no words of its own falls back to the alt of its pictures: the block token
	 * carries no alt, so those words are free to name the link instead of being shown twice.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function takeImagesOutOfLabel(string $label): array
	{
		if (!str_contains($label, '!['))
		{
			return ['', $label];
		}

		$images = [];
		$alts = [];
		$rest = preg_replace_callback(
			self::IMAGE_MARKDOWN_PATTERN,
			static function (array $match) use (&$images, &$alts): string {
				$images[] = $match[0];
				$alts[] = $match['alt'];

				return ' ';
			},
			$label,
		) ?? $label;

		if ($images === [])
		{
			return ['', $label];
		}

		$rest = trim(preg_replace('/\s+/u', ' ', $rest) ?? $rest);

		return [implode(' ', $images), $rest === '' ? trim(implode(' ', $alts)) : $rest];
	}

	/**
	 * The address of a `mailto:`/`tel:` target. The note editor keeps `http`/`https` links only
	 * (`install/js/note/editor/src/utils/url.js`), so as a link the contact would reach the reader
	 * stripped down to its label - and the mailbox or the phone number of the page is exactly what
	 * the reader came for. Returns null for every other target.
	 */
	private static function contactAddress(string $href): ?string
	{
		if (!preg_match(self::CONTACT_HREF_PATTERN, $href))
		{
			return null;
		}

		$address = (string)substr($href, (int)strpos($href, ':') + 1);
		// `mailto:` may carry headers (`?subject=..`) - those name no contact.
		$queryAt = strpos($address, '?');
		if ($queryAt !== false)
		{
			$address = substr($address, 0, $queryAt);
		}
		$address = trim($address);

		return $address === '' ? null : $address;
	}

	/**
	 * The contact as it goes into the text: the label, followed by the address when the label does
	 * not already tell it. A label of the page's own words ("Напишите нам") keeps the address it
	 * stood for, a label that is the address stays as it is.
	 */
	private static function contactText(string $label, string $address): string
	{
		$escaped = self::escapeInlineMarkup($address);
		if ($label === '')
		{
			return $escaped;
		}

		return self::labelShowsAddress($label, $address) ? $label : $label . ' (' . $escaped . ')';
	}

	/**
	 * True when the label already tells the address, so writing it out again would only repeat it.
	 * Compared by letters and digits alone: the label spells the same number by its own punctuation
	 * (`+7 (495) 123-45-67` against `tel:+74951234567`) and carries the escapes of the text.
	 */
	private static function labelShowsAddress(string $label, string $address): bool
	{
		$reduce = static fn(string $value): string
			=> mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value);

		$reduced = $reduce($address);

		return $reduced !== '' && str_contains($reduce($label), $reduced);
	}

	/**
	 * True when the target may be written as a link. A target with no scheme - a path of the portal,
	 * a fragment, a landing binding (`#landing81`) - is the import's own business: it is resolved or
	 * degraded to its label by {@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource}, which
	 * is also what the binding schemes are kept for. Of the schemes written out, only the two the note
	 * editor accepts may stay: anything else (`javascript:`, `data:`, `file:///`) loses its link mark
	 * on read anyway, so storing the address would leave one nothing can ever follow.
	 */
	private static function isLinkableHref(string $href): bool
	{
		if (!preg_match(self::HREF_SCHEME_PATTERN, $href))
		{
			return true;
		}

		return (bool)preg_match(self::LINKABLE_HREF_PATTERN, $href);
	}

	/**
	 * Percent-encodes what would end a markdown link destination early - whitespace and round
	 * brackets. These are their canonical escapes, so the address keeps working. The same rule
	 * {@see \Bitrix\Note\Internal\Service\Import\Transformer\LandingMdTransformer} applies to the
	 * addresses it unwraps.
	 */
	private static function markdownSafeUrl(string $url): string
	{
		$url = (string)preg_replace('/\s/', '%20', $url);

		return str_replace(['(', ')'], ['%28', '%29'], $url);
	}

	/**
	 * The label of a link that does point somewhere. A `#crm...`-looking word there is the author's own
	 * text - a hashtag, a promo name - and not a binding landing failed to fill in: dropping it would
	 * leave a real link labelled by its own URL. Pinned by `testRealLinkWithCrmLikeLabelIsKept`.
	 */
	private function renderLiveLinkLabel(\DOMElement $node, ?BlockMediaContext $media): string
	{
		$this->liveLinkDepth++;
		try
		{
			return $this->renderInline($node, $media);
		}
		finally
		{
			$this->liveLinkDepth--;
		}
	}

	/**
	 * A dead link target: empty, a bare `#`, or a landing substitution nothing filled in - an unresolved
	 * CRM action (`tel:#crmPhone1` / `mailto:#crmEmail1` / `#crmCompanyUrl`), a system-page target
	 * (`#system_mainpage`, `#system_personal?SECTION=orders`) or a meta marker (`#title#`). Empty social
	 * icons, unbound phone/email buttons and the store menus carry these; they must not become links.
	 * Real URLs and the click-action anchors (`#landing81`, `user:#user5`, `form:#crmFormInline2`) are
	 * deliberately left intact - LandingSource resolves those to portal URLs afterwards.
	 */
	private function isDeadHref(string $href): bool
	{
		return $href === '' || $href === '#' || self::isSubstitutionHref($href);
	}

	/**
	 * The half of {@see isDeadHref()} that is a landing substitution rather than a missing target, and
	 * the only half the converters that build a link out of extracted attributes instead of walking the
	 * markup ({@see BlockConverter\CardTableConverter::cardLink()}, {@see BlockConverter\ButtonConverter})
	 * may act on. A bare `#` fragment is dead here as well, but it is a jump the page really wrote and
	 * those converters leave its fate to {@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource};
	 * a substitution has no fate to decide - nothing will ever fill it in.
	 */
	public static function isSubstitutionHref(string $href): bool
	{
		return (bool)preg_match('/^(?:tel:|mailto:)?#(?:crm|requisite|system_|title#|breadcrumb#|content#|year#)/i', $href);
	}

	/**
	 * Drops the landing substitutions {@see SUBSTITUTION_PATTERN} names and folds the whitespace they
	 * leave behind, so a phrase built around one stays readable ("Email" rather than "Email  "). A mask
	 * goes whether it is the whole text (`#crmPhoneTitle1` in `52.3`), part of a phrase
	 * (`Email #crmEmailTitle1` in `14.3contacts_2_cols`) or the label of a link (`store.store_v3_menu_1`).
	 *
	 * Public for the same reason as {@see isSubstitutionHref()}: it is the one rule, and the converters
	 * that assemble a link out of extracted attributes have to apply it too.
	 */
	public static function dropSubstitutions(string $text): string
	{
		$text = preg_replace(self::SUBSTITUTION_PATTERN, '', $text) ?? $text;

		return preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
	}

	/**
	 * Wraps rendered markdown in a `{color:VALUE}...{/color}` token when the element carries a text
	 * color the note editor can restore. Blank inner content is returned untouched (no empty wrap),
	 * and an unrecognized/unsafe color simply degrades to the plain markdown.
	 */
	private function wrapColor(\DOMElement $node, string $inner): string
	{
		if ($this->isBlank($inner))
		{
			return $inner;
		}

		$color = $this->extractColor($node);
		if ($color === null)
		{
			return $inner;
		}

		return '{color:' . $color . '}' . $inner . '{/color}';
	}

	/**
	 * Reads a text color the user set *explicitly* on the element - an inline `style="color:..."` or a
	 * legacy `<font color>` attribute - and returns it normalized/safe. Landing `g-color-*` utility
	 * classes are deliberately NOT mapped: their value only exists as a class-name and reproducing it
	 * would require a hardcoded style-mapping table, so a block that carries no explicit color value
	 * keeps its default color (this method returns null and {@see wrapColor()} leaves the text plain).
	 */
	private function extractColor(\DOMElement $node): ?string
	{
		$style = $node->getAttribute('style');
		if ($style !== '' && preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $m))
		{
			$color = $this->normalizeColor($m[1]);
			if ($color !== null)
			{
				return $color;
			}
		}

		if (strtolower($node->nodeName) === 'font')
		{
			$attr = trim($node->getAttribute('color'));
			if ($attr !== '')
			{
				$color = $this->normalizeColor($attr);
				if ($color !== null)
				{
					return $color;
				}
			}
		}

		return null;
	}

	/**
	 * Whitelists CSS color values so the `{color:...}` token can only ever yield a safe inline
	 * `color:` style downstream: hex (#rgb / #rgba / #rrggbb / #rrggbbaa), rgb()/rgba(), hsl()/hsla(),
	 * or a bare CSS named color. Anything else (url(), expression(), CSS keywords, over-long input)
	 * is rejected so the token cannot become a style-injection vector.
	 */
	private function normalizeColor(string $value): ?string
	{
		$value = trim($value);
		if ($value === '' || mb_strlen($value) > 32)
		{
			return null;
		}

		$normalized = null;
		if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value))
		{
			$normalized = strtolower($value);
		}
		elseif (
			preg_match('/^rgba?\(\s*[0-9.,%\s]+\)$/i', $value)
			|| preg_match('/^hsla?\(\s*[0-9.,%\s]+\)$/i', $value)
		)
		{
			$normalized = strtolower(preg_replace('/\s+/', ' ', $value) ?? $value);
		}
		elseif (preg_match('/^[a-z]{3,20}$/i', $value))
		{
			// A CSS named color (e.g. "red", "cornflowerblue"). Reject the CSS keywords that are not
			// concrete colors and would be meaningless or unsafe as a value.
			$lower = strtolower($value);
			$reserved = ['inherit', 'initial', 'unset', 'revert', 'currentcolor', 'transparent', 'none', 'auto'];
			$normalized = in_array($lower, $reserved, true) ? null : $lower;
		}

		// White text is dropped: on the note editor's light document canvas it would be invisible. A
		// landing block that set white text did so against its own dark/colored background, which the KB
		// does not reproduce, so the run simply keeps the editor's default text color.
		if ($normalized === null || $this->isWhiteColor($normalized))
		{
			return null;
		}

		return $normalized;
	}

	/**
	 * True for the pure-white spellings (any alpha): `#fff` / `#ffff` / `#ffffff` / `#ffffffff`, the
	 * `white` keyword, `rgb(255, 255, 255)` and `hsl(*, *, 100%)`. Expects an already-normalized value
	 * (lowercased, single-spaced) as produced by {@see normalizeColor()}. This rule is import-only and
	 * has no counterpart in the editor: `isSafeColor()` in formatting-extensions.js keeps a white the
	 * user picks, since the document canvas follows the theme.
	 */
	private function isWhiteColor(string $normalized): bool
	{
		if (in_array($normalized, ['#fff', '#ffff', '#ffffff', '#ffffffff', 'white'], true))
		{
			return true;
		}

		// rgb(255, 255, 255) / rgba(255, 255, 255, a) - all RGB channels maxed.
		if (preg_match('/^rgba?\(\s*255\s*,\s*255\s*,\s*255\s*[,)]/', $normalized))
		{
			return true;
		}

		// hsl(h, s%, 100%) / hsla(...) - a lightness of 100% is white for any hue/saturation.
		return (bool)preg_match('/^hsla?\(\s*[0-9.]+\s*,\s*[0-9.]+%\s*,\s*100%\s*[,)]/', $normalized);
	}

	/**
	 * The label of an image, made safe to sit between the brackets of `![...]`: the whitespace of the
	 * alt is folded onto the single line an image line is, and the markup characters left in it are
	 * escaped by the rule every extracted label follows ({@see BlockHtmlExtractor::escapeMarkdownInline()}),
	 * so a `*` or an underscore of the alt stays a character instead of forging structure. Its square
	 * brackets go instead of being escaped ({@see BlockHtmlExtractor::dropLabelBrackets()}): an alt is
	 * what the attachment chain finds the image by, and an escaped bracket hides it from that chain.
	 *
	 * Public for the same reason as {@see linkOrText()}: the converters that build an image out of
	 * extracted attributes ({@see BlockConverter\GalleryConverter}, {@see BlockConverter\VideoConverter})
	 * write the same label, and what the reader sees must not depend on which of them wrote it.
	 */
	public static function escapeImageAlt(string $alt): string
	{
		$alt = trim(preg_replace('/\s+/u', ' ', $alt) ?? $alt);

		return BlockHtmlExtractor::dropLabelBrackets(BlockHtmlExtractor::escapeMarkdownInline($alt));
	}

	private function renderImage(\DOMElement $node, ?BlockMediaContext $media = null): string
	{
		$src = trim($node->getAttribute('src'));
		$alt = self::escapeImageAlt($node->getAttribute('alt'));
		if ($src === '')
		{
			return '';
		}

		$suffix = BlockHtmlExtractor::positionQuerySuffix(
			BlockHtmlExtractor::extractWidthPercent($node),
			BlockHtmlExtractor::extractAlign($node),
		);

		// The <img>'s own file id is authoritative. Prefer the retina `data-fileid2x` when present -
		// it is the highest-resolution managed copy, so avatars/photos import at the sharpest size
		// ("максимального размера") rather than the down-scaled @1x. Landing lists a block's @2x files
		// alongside the @1x ones, so a block with N images can expose 2N file ids; resolving straight
		// from the attribute (and never advancing the positional cursor - both ids are excluded from
		// the pool, see BlockMediaContext::forContent) keeps every card on its own picture.
		$dataFileId = (int)$node->getAttribute('data-fileid2x');
		if ($dataFileId <= 0)
		{
			$dataFileId = (int)$node->getAttribute('data-fileid');
		}
		if ($dataFileId > 0)
		{
			return '![' . $alt . '](' . LandingSource::ATTACHMENT_SCHEME . $dataFileId . $suffix . ')';
		}

		$fileId = $media?->nextFileId();
		if ($fileId !== null)
		{
			return '![' . $alt . '](' . LandingSource::ATTACHMENT_SCHEME . $fileId . $suffix . ')';
		}

		// External http(s) image with no local file behind it: wrap it in the download
		// placeholder so DownloadAttachmentsStep fetches it and it becomes a viewer-backed
		// note file. Non-http sources (data:, relative) are left as-is.
		$external = LandingSource::externalAttachmentUrl($src);
		if ($external !== null)
		{
			return '![' . $alt . '](' . $external . $suffix . ')';
		}

		return '![' . $alt . '](' . $src . $suffix . ')';
	}

	/**
	 * Embedded videos (<iframe>) are otherwise silently dropped by the default child walk.
	 * Whitelisted embed hosts (see {@see VideoConverter}) are emitted as a standalone URL so
	 * the note editor's embed extension turns the paragraph into a video; anything else keeps
	 * the source reachable by {@see linkOrText()} - as a link where the document may carry one,
	 * and as the address itself where it may not.
	 */
	private function renderIframe(\DOMElement $node): string
	{
		$src = trim($node->getAttribute('src'));
		if ($src === '')
		{
			return '';
		}

		if (VideoConverter::isEmbedWhitelisted($src))
		{
			return "\n\n" . $src . "\n\n";
		}

		return self::linkOrText(self::escapeInlineMarkup($src), $src);
	}

	/**
	 * Landing background/embed video marker (`.landing-block-node-video`): the URL lives on the
	 * element's data-source (canonical page URL) / data-src (embed URL). Emits the canonical URL
	 * when present - a whitelisted host (see {@see VideoConverter}) becomes a standalone URL the
	 * note editor turns into a player, everything else degrades through {@see linkOrText()}. Mirrors
	 * {@see SliderConverter::videoLines()} so videos survive in non-slider blocks too.
	 */
	private function renderVideoNode(\DOMElement $node): string
	{
		// data-source is the clean page URL (e.g. vimeo.com/<id>) which ui.video-service resolves to
		// a player; data-src is the raw embed URL fallback (e.g. //player.vimeo.com/video/<id>?...).
		$src = trim($node->getAttribute('data-source'));
		if ($src === '')
		{
			$src = trim($node->getAttribute('data-src'));
		}
		$src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($src === '')
		{
			return '';
		}

		if (VideoConverter::isEmbedWhitelisted($src))
		{
			return "\n\n" . $src . "\n\n";
		}

		return self::linkOrText(self::escapeInlineMarkup($src), $src);
	}

	/**
	 * Landing inline CRM-form embed marker (`<div data-b24form="#crmForm{Inline|Popup}<id>">`): emit a
	 * link carrying the `form:#...` binding scheme so {@see LandingSource::resolveBodyInternalLinks()}
	 * can turn it into the form's public URL (or drop it to plain text when unresolvable). Bindings
	 * that are not a recognizable `#crmForm{Inline|Popup}<id>` token are dropped - nothing to link to.
	 */
	private function renderFormEmbed(\DOMElement $node): string
	{
		$binding = trim($node->getAttribute('data-b24form'));
		if (!preg_match('/^#crmForm(?:Inline|Popup)\d+$/i', $binding))
		{
			return '';
		}

		$label = (string)Loc::getMessage('NOTE_IMPORT_LANDING_FORM_LINK');
		if ($label === '')
		{
			// An empty label is not merely ugly here: the binding resolver matches a non-empty
			// label only ({@see LandingSource::resolveBodyInternalLinks()}), so `[](form:#...)`
			// would carry the raw binding href into the editor, which rejects it. Nothing to
			// name it with, nothing to link - the same answer as for an unreadable binding.
			return '';
		}

		return '[' . $label . '](form:' . $binding . ')';
	}

	private function hasCssClass(\DOMElement $node, string $class): bool
	{
		$classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];

		return in_array($class, $classes, true);
	}

	private function renderBlockquote(\DOMElement $node, int $listDepth, ?BlockMediaContext $media = null): string
	{
		$inner = trim($this->renderNodes($node->childNodes, $listDepth, $media));
		if ($inner === '')
		{
			return '';
		}

		$lines = preg_split("/\r?\n/", $inner) ?: [];
		$quoted = array_map(static fn(string $l): string => '> ' . ltrim($l), $lines);

		return "\n\n" . implode("\n", $quoted) . "\n\n";
	}

	private function renderPre(\DOMElement $node): string
	{
		$inner = $this->getRawText($node);
		if ($inner === '')
		{
			return '';
		}

		$language = '';
		$codeChild = null;
		foreach ($node->childNodes as $child)
		{
			if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'code')
			{
				$codeChild = $child;
				break;
			}
		}
		if ($codeChild !== null)
		{
			$class = $codeChild->getAttribute('class');
			if (preg_match('/(?:^|\s)language-([a-z0-9_+-]+)/i', $class, $m))
			{
				$language = strtolower($m[1]);
			}
			$inner = $this->getRawText($codeChild);
		}

		return "\n\n```" . $language . "\n" . rtrim($inner, "\n") . "\n```\n\n";
	}

	private function renderList(\DOMElement $node, int $listDepth, bool $ordered, ?BlockMediaContext $media = null): string
	{
		$marker = static fn(int $idx): string => $ordered ? ($idx . '.') : '-';
		$indent = str_repeat('  ', $listDepth);

		$lines = [];
		$index = 1;
		foreach ($node->childNodes as $child)
		{
			if (!($child instanceof \DOMElement) || strtolower($child->nodeName) !== 'li')
			{
				continue;
			}

			$itemBody = '';
			$nestedLists = '';
			foreach ($child->childNodes as $grand)
			{
				if ($grand instanceof \DOMElement && in_array(strtolower($grand->nodeName), ['ul', 'ol'], true))
				{
					$nestedLists .= "\n" . $this->renderList($grand, $listDepth + 1, strtolower($grand->nodeName) === 'ol', $media);
				}
				else
				{
					$itemBody .= $this->renderNode($grand, $listDepth + 1, $media);
				}
			}

			$itemBody = trim($itemBody);
			$nestedLists = rtrim($nestedLists);
			// A list item whose only content was an empty social icon / dropped placeholder link
			// renders to nothing - skip it so the document has no blank bullets and the numbering
			// of an ordered list stays contiguous.
			if ($itemBody === '' && $nestedLists === '')
			{
				continue;
			}
			$lines[] = $indent . $marker($index) . ' ' . $itemBody . $nestedLists;
			$index++;
		}

		if (empty($lines))
		{
			return '';
		}

		return ($listDepth === 0 ? "\n\n" : "\n") . implode("\n", $lines) . ($listDepth === 0 ? "\n\n" : '');
	}

	private function getRawText(\DOMNode $node): string
	{
		$text = '';
		foreach ($node->childNodes as $child)
		{
			if ($child->nodeType === XML_TEXT_NODE)
			{
				$text .= $child->nodeValue ?? '';
			}
			elseif ($child instanceof \DOMElement)
			{
				$text .= $this->getRawText($child);
			}
		}

		return $text;
	}

	/**
	 * Text as it stood in a text node, made safe to sit in markdown: an unresolved landing substitution
	 * is dropped, whitespace runs are folded and every character markdown would read as markup is
	 * escaped. Only text goes through here - a link target, the verbatim body of a code block and the
	 * markup this class builds itself never do.
	 */
	private function escapeInline(string $text): string
	{
		if ($this->liveLinkDepth === 0)
		{
			$text = self::dropSubstitutions($text);
		}

		// Collapse whitespace runs while preserving leading/trailing single-space context.
		$text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
		$text = str_replace(["\r\n", "\r"], "\n", $text);

		return $this->escapeMarkup($text);
	}

	/**
	 * Escapes the markup characters of the text. Inline markers bite anywhere in a line and are escaped
	 * wherever they stand; block markers open a construct only at the start of a line and are escaped
	 * there alone, or every dash and every numbered sentence of the prose would carry a backslash. The
	 * escape is markdown's own, so the reader is shown the character itself and never the backslash.
	 */
	private function escapeMarkup(string $text): string
	{
		$parts = preg_split(self::BARE_URL_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
		foreach ($parts as $index => $part)
		{
			// Odd positions are the addresses the split captured - they are left as they were written.
			if ($index % 2 === 0)
			{
				$parts[$index] = self::escapeInlineMarkup($part);
			}
		}
		$text = implode('', $parts);

		// Heading, bullet, quote and ordered-list markers.
		$text = preg_replace('/^([ \t]*)(#{1,6})(?=[ \t]|$)/mu', '$1\\\\$2', $text) ?? $text;
		$text = preg_replace('/^([ \t]*)([-+>])/mu', '$1\\\\$2', $text) ?? $text;
		$text = preg_replace('/^([ \t]*\d{1,9})([.)])(?=[ \t]|$)/mu', '$1\\\\$2', $text) ?? $text;

		return $text;
	}

	/** The inline half of {@see escapeMarkup()}: the markers that bite wherever they stand in a line. */
	private static function escapeInlineMarkup(string $text): string
	{
		return preg_replace(self::INLINE_MARKUP_PATTERN, '\\\\$0', $text) ?? $text;
	}

	private function normalizeWhitespace(string $markdown): string
	{
		// A `<br>` emits a two-space soft line break; keep exactly that form and strip trailing
		// whitespace everywhere else, or the break the source asked for is normalized away. The
		// lookbehind is what protects the break: without it the second pass eats one of its spaces.
		$markdown = preg_replace("/[ \t]{2,}\n/", "  \n", $markdown) ?? $markdown;
		$markdown = preg_replace("/(?<![ \t])[ \t]\n/", "\n", $markdown) ?? $markdown;
		$markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

		return trim($markdown);
	}

	/** Markup that could not be parsed at all: what is left is text, and it is treated as such. */
	private function fallbackText(string $html): string
	{
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return trim($this->escapeInline($text));
	}
}
