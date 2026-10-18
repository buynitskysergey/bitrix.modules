<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block codes matching /^\d+\.\d+\.(button|cta)/ - call-to-action buttons.
 *
 * Renders as a bold markdown link `[**text**](href)`, built by the link rule inline <a> links
 * follow ({@see \Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown::linkOrText()}).
 * In particular landing cross-page hrefs (`#landing<id>` / `page:#landing<id>`) are kept so
 * that {@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource} resolveBodyInternalLinks
 * can rewrite them to /knowledge/... (later a /note/document/<id>/ mention). Bare `#fragment`
 * anchors and dead landing ids are downgraded to plain text there. A call/write-to-us button
 * keeps its `tel:`/`mailto:` address in the text, a scheme the editor drops keeps the words of
 * the button alone, and an empty href falls back to bold text with no link at all.
 */
final class ButtonConverter implements Converter
{
	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		if ($content === '')
		{
			return '';
		}

		$href = '';
		$text = '';

		// Try the explicit button-node selector first.
		$buttonHtml = BlockHtmlExtractor::extractBySelector($content, 'landing-block-node-button');
		if ($buttonHtml !== '')
		{
			$href = BlockHtmlExtractor::extractFirstAttribute($buttonHtml, 'a', 'href');
			$text = trim(strip_tags(html_entity_decode($buttonHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		}

		if ($href === '')
		{
			$href = BlockHtmlExtractor::extractFirstAttribute($content, 'a', 'href');
		}
		if ($text === '')
		{
			$text = trim(strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		}

		$text = preg_replace('/\s+/u', ' ', HtmlToMarkdown::dropSubstitutions($text)) ?? $text;
		$text = trim($text);
		if ($text === '')
		{
			return '';
		}

		// The words of the button are page text read out of the markup and then wrapped in markdown
		// structure of our own - the same position a card label or a slide title is in, so they are
		// escaped by the same rule: a `[`, a `*` or an underscore of the button stays a character.
		$text = BlockHtmlExtractor::escapeMarkdownInline($text);

		// An unresolved landing substitution is no address: `66.100.form_cb` binds its button to
		// `#crmFormCallback`, a CRM form nothing will ever fill in. The button keeps its words and
		// loses the link (P7.T8). Bare `#` fragments are left alone - those are real jumps of the
		// page and the downgrade below belongs to LandingSource, as the docblock says.
		if (HtmlToMarkdown::isSubstitutionHref($href))
		{
			$href = '';
		}

		if ($href === '')
		{
			return '**' . $text . '**';
		}

		// Emit the href by the one link rule - same contract as inline links. In particular a landing
		// `#landing<id>` / `page:#landing<id>` cross-page href must survive to
		// LandingSource::resolveBodyInternalLinks, which rewrites it to a /knowledge/ URL and
		// downgrades bare `#fragment` anchors / dead targets to plain text. Dropping `#` hrefs
		// here would silently strip real cross-page CTA links before they can be resolved, while a
		// contact button written as a link would reach the reader stripped down to its label.
		return HtmlToMarkdown::linkOrText('**' . $text . '**', $href);
	}
}
