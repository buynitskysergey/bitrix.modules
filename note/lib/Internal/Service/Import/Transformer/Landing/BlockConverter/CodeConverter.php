<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block: 27.7.one_col_fix_text_on_bg - text on a coloured panel, rendered by its markup:
 *
 * - code (a preformatted or monospace run) -> fenced code block, plain text as note's code block is;
 * - prose -> callout, keeping its formatting.
 *
 * Landing's own generator stores real code here (it strips the `<pre>` wrapper before saving, so
 * the snippet arrives as a bare text run), while a knowledge base author uses the panel to
 * highlight prose. Stripping the tags off that prose is what used to lose its formatting.
 */
final class CodeConverter implements Converter
{
	private const TEXT_SELECTOR = 'landing-block-node-text';
	/** Neutral callout type: the panel means "important", with no success/warning shade to it. */
	private const CALLOUT_TYPE = 'info';
	/** Tags that make the panel read as authored prose rather than as a snippet. */
	private const PROSE_TAGS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'table'];

	public function __construct(
		private readonly HtmlToMarkdown $html = new HtmlToMarkdown(),
	)
	{
	}

	public function convert(array $block): string
	{
		$snippet = self::snippet((string)($block['CONTENT'] ?? ''));

		return self::isCode($snippet)
			? $this->renderCodeBlock($snippet)
			: $this->renderCallout($snippet);
	}

	private function renderCodeBlock(string $snippet): string
	{
		$text = trim(html_entity_decode(strip_tags($snippet), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($text === '')
		{
			return '';
		}

		return "```\n" . $text . "\n```";
	}

	private function renderCallout(string $snippet): string
	{
		$md = trim($this->html->convert($snippet));
		if ($md === '')
		{
			return '';
		}

		return ':::' . self::CALLOUT_TYPE . "\n" . $md . "\n:::";
	}

	private static function isCode(string $snippet): bool
	{
		if (trim(strip_tags($snippet)) === '')
		{
			return false;
		}

		if (preg_match('#<(?:pre|code)[\s>/]#i', $snippet) === 1)
		{
			return true;
		}

		// A monospace font on the container is the other way landing marks a snippet up.
		if (preg_match('#<[^>]+monospace[^>]*>#i', $snippet) === 1)
		{
			return true;
		}

		return preg_match('#<(?:' . implode('|', self::PROSE_TAGS) . ')[\s>/]#i', $snippet) !== 1;
	}

	private static function snippet(string $content): string
	{
		$snippet = BlockHtmlExtractor::extractBySelector($content, self::TEXT_SELECTOR);

		return $snippet === '' ? $content : $snippet;
	}
}
