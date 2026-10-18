<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockMediaContext;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Landing question-answer blocks (`68.1`-`68.6`) - the only collapsible format of the delivery.
 *
 * Each card keeps its question in `landing-block-faq-visible` (or in a title node inside it) and its
 * answer in `landing-block-faq-hidden`. No other converter reads those classes, so such a block used
 * to come out either as a table whose single cell glues question and answer together (the card
 * detector) or as a run of paragraphs whose heading level follows whatever tag the template happened
 * to use (the fallback).
 *
 * The question becomes a heading of one fixed level and the answer the body under it - the same
 * flattening {@see AccordionConverter} applies to a spoiler, because note has no collapsible node and
 * a collapsed answer has to be visible in the document. Content that lies outside the cards (block
 * heading, intro text, lead image) leads the output so nothing of the block is dropped.
 *
 * Routed by markup, never by CODE: the six delivery codes live in landing's release cycle.
 */
final class FaqConverter implements Converter
{
	/** The answer subtree; in some templates it is nested INSIDE the question node (68.5). */
	private const ANSWER_SELECTOR = 'landing-block-faq-hidden';
	/** Question node classes, most specific first - the last one is the pair's own wrapper. */
	private const QUESTION_SELECTORS = [
		'landing-block-faq-visible-text',
		'landing-block-faq-title',
		'landing-block-node-card-title',
		'landing-block-faq-visible',
	];
	/** Same level a spoiler section gets ({@see AccordionConverter}), never colliding with the block heading. */
	private const QUESTION_LEVEL = '####';

	public function __construct(
		private readonly HtmlToMarkdown $html = new HtmlToMarkdown(),
		private readonly FileReader $files = new FileReader(),
	)
	{
	}

	/**
	 * True when the block carries at least one question-answer pair these selectors do read. A block
	 * whose pairs cannot be read belongs to another converter, not to a half-empty FAQ list.
	 */
	public static function matches(string $content): bool
	{
		return self::pairs($content) !== [];
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		$pairs = self::pairs($content);
		if ($pairs === [])
		{
			return '';
		}

		$blockId = (int)($block['ID'] ?? 0);
		// One cursor per block, shared by the lead content and every answer, so images without an
		// explicit data-fileid consume the block's FILE_IDs in document order.
		$media = BlockMediaContext::forContent(
			$blockId > 0 ? $this->files->filesForBlock($blockId) : [],
			$content,
		);

		$parts = [];
		$lead = trim($this->html->convert(
			BlockHtmlExtractor::stripNodes($content, BlockHtmlExtractor::CARD_CLASSES),
			$media,
		));
		if ($lead !== '')
		{
			$parts[] = $lead;
		}

		foreach ($pairs as $pair)
		{
			$section = $this->renderPair($pair['question'], $pair['answer'], $media);
			if ($section !== '')
			{
				$parts[] = $section;
			}
		}

		return implode("\n\n", $parts);
	}

	private function renderPair(string $questionHtml, string $answerHtml, BlockMediaContext $media): string
	{
		$question = $this->headingText($questionHtml, $media);
		$answer = trim($this->html->convert($answerHtml, $media));

		$parts = [];
		if ($question !== '')
		{
			$parts[] = self::QUESTION_LEVEL . ' ' . $question;
		}
		if ($answer !== '')
		{
			$parts[] = $answer;
		}

		return implode("\n\n", $parts);
	}

	/**
	 * The question on a single line: a heading cannot hold a line break, and landing writes long
	 * questions across several source lines (and sometimes with a `<br>` in them).
	 */
	private function headingText(string $html, BlockMediaContext $media): string
	{
		$markdown = $this->html->convert($html, $media);
		$markdown = preg_replace('/\s*\R\s*/u', ' ', $markdown) ?? $markdown;

		return trim($markdown);
	}

	/**
	 * Question/answer html of every card that carries at least one of the two, in document order.
	 *
	 * The answer subtree is taken out of the html the question is read from: several templates nest
	 * the collapsed answer inside the clickable question, and without that the question would carry
	 * its own answer along.
	 *
	 * @return array<int, array{question: string, answer: string}>
	 */
	private static function pairs(string $content): array
	{
		if ($content === '' || stripos($content, 'landing-block-faq-') === false)
		{
			return [];
		}

		$pairs = [];
		foreach (BlockHtmlExtractor::extractCardNodes($content) as $card)
		{
			$answer = BlockHtmlExtractor::extractBySelector($card['html'], self::ANSWER_SELECTOR);
			$question = self::questionHtml(BlockHtmlExtractor::stripNodes($card['html'], [self::ANSWER_SELECTOR]));

			if (self::isBlank($question) && self::isBlank($answer))
			{
				continue;
			}

			$pairs[] = ['question' => $question, 'answer' => $answer];
		}

		return $pairs;
	}

	private static function questionHtml(string $cardHtml): string
	{
		foreach (self::QUESTION_SELECTORS as $selector)
		{
			$html = BlockHtmlExtractor::extractBySelector($cardHtml, $selector);
			if (!self::isBlank($html))
			{
				return $html;
			}
		}

		return '';
	}

	/**
	 * True when the html holds neither text nor media - an icon-only wrapper, a decorative spacer.
	 */
	private static function isBlank(string $html): bool
	{
		if (preg_replace('/[\pZ\s]+/u', '', strip_tags($html)) !== '')
		{
			return false;
		}

		return !preg_match('/<(?:img|iframe|video|audio|table)\b/i', $html);
	}
}
