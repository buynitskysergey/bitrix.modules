<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Search;

final class MarkdownStripper
{
	/**
	 * @var string[]
	 */
	private const PATTERNS = [
		// HTML comments (multi-line).
		'/<!--.*?-->/su',
		// Fenced code-block fences (keep the body in between).
		'/^\s*```[^\n]*$/mu',
		// Enriched-asset `![label](url){type=image fileId=N ...}` — must run BEFORE plain images.
		'/!\[[^\]]*\]\([^)]*\)\{[^}]*\}/u',
		// Plain images `![alt](url)`.
		'/!\[[^\]]*\]\([^)]*\)/u',
		// Compact asset tokens `[[image|video|file fileId=N ...]]`.
		'/\[\[(?:image|video|file) fileId=\d+[^\]\n]*\]\]/u',
		// Table separator rows, with optional alignment (`:---`, `---:`, `:---:`).
		'/^\s*\|?\s*:?-{3,}:?(?:\s*\|\s*:?-{3,}:?)+\s*\|?\s*$/mu',
		// Table data rows: strip leading/trailing pipe. Inner pipes are collapsed in the post-pass.
		'/^\s*\|(.+)\|\s*$/mu',
		// Callout fences `:::info` / `:::tip` / closing `:::`.
		'/^:::\s*\w*\s*$/mu',
		// Mention token `@{type:id}` — strip entirely (label not available at this layer).
		'/@\{[a-z]+:\d+\}/u',
		// Strikethrough `~~text~~` — keep text.
		'/~~([^~]+)~~/u',
		// Text-color wrappers `{color:VALUE}text{/color}` - strip both markers, keep the text.
		'/\{color:[^}\n]+\}/u',
		'/\{\/color\}/u',
		// Existing patterns.
		'/^#{1,6}\s+/mu',            // headings
		'/\*{1,3}([^*]+)\*{1,3}/u',  // bold / italic (*)
		'/`{1,3}([^`\n]*)`{1,3}/u',  // inline code: keep text, strip backticks (fenced blocks already gone)
		'/^\s*[-*+]\s+/mu',          // bullet lists
		'/^\s*\d+\.\s+/mu',          // ordered lists
		'/\[([^\]]+)\]\([^)]+\)/u',  // links (keep text)
		'/^>\s+/mu',                 // blockquote
		'/^-{3,}$/mu',               // horizontal rule
		// bold / italic (_): only when nothing alphanumeric touches the pair from the outside, so
		// snake_case stays a word; the run also stops at a line break, or two unrelated underscores
		// from neighbouring paragraphs would pair up and swallow everything between them.
		'/(?<![\p{L}\p{N}])_{1,2}([^_\n]+)_{1,2}(?![\p{L}\p{N}])/u',
	];

	/**
	 * @var string[]
	 */
	private const REPLACEMENTS = [
		'',          // HTML comments
		'',          // fenced code fences
		'',          // enriched asset
		'',          // plain images
		'',          // compact asset tokens
		'',          // table separator
		'$1',        // table data row
		'',          // callout fences
		'',   // mention token: strip entirely
		'$1',        // strikethrough
		'',          // color wrapper open
		'',          // color wrapper close
		'',          // headings
		'$1',        // bold / italic (*)
		'$1',        // inline code
		'',          // bullet lists
		'',          // ordered lists
		'$1',        // links
		'',          // blockquote
		'',          // horizontal rule
		'$1',        // bold / italic (_)
	];

	/**
	 * Characters the editor escapes in a stored document body: the set @tiptap/markdown escapes on
	 * its own plus the vertical bar, which the module escapes in the plain text of a table cell.
	 * Held in order: the placeholder each one is hidden behind is its index plus one.
	 */
	private const ESCAPED_CHARACTERS = ['\\', '`', '*', '_', '[', ']', '~', '|'];

	public function strip(string $md): string
	{
		// Escaped characters are hidden behind placeholders before the patterns run and restored at
		// the very end. Both halves of that are needed. Leaving the backslashes in place lets the
		// patterns misread them - the link pattern takes `\[text\](url)` for the text `text\` and
		// keeps the trailing backslash. Dropping them beforehand is the opposite mistake: what the
		// author typed literally would turn back into markdown, and the pattern for it would then eat
		// it - `\[\[image fileId=5\]\]` is a phrase a person wrote, not an attachment, and it would
		// disappear from the index and from the snippet.
		$protected = $this->replaceDateTokens($this->protectEscapedCharacters($md));

		$stripped = preg_replace(self::PATTERNS, self::REPLACEMENTS, $protected);
		if ($stripped === null)
		{
			return $md;
		}

		// Collapse pipes left over from table cells (after the data-row pattern removed outer ones).
		$stripped = (string)preg_replace('/[ \t]*\|[ \t]*/u', ' ', $stripped);
		// Collapse runs of blank lines to a single blank.
		$stripped = (string)preg_replace('/\n{3,}/u', "\n\n", $stripped);

		return $this->restoreEscapedCharacters($stripped);
	}

	/**
	 * Replaces `[[date YYYY-MM-DD]]` with its machine value, and only for a date the calendar has.
	 *
	 * The value is not localized: the index and the preview are rebuilt on document change, not on
	 * portal culture change, so a localized string would drift from the setting.
	 *
	 * This one cannot live in PATTERNS: the form of the token is not enough to decide. The editor
	 * mirrors this grammar in note-date-parser.js, where matchDateToken() runs the parts through
	 * normalizeDateValue() - so `[[date 2026-02-31]]` never becomes a node and stays literal text in
	 * the body. A form-only pattern here would put `2026-02-31` in the index while the reader sees the
	 * brackets, and the two projections of one document would disagree.
	 */
	private function replaceDateTokens(string $text): string
	{
		$replaced = preg_replace_callback(
			'/\[\[date (\d{4})-(\d{2})-(\d{2})\]\]/u',
			static function (array $match): string
			{
				$year = (int)$match[1];
				$month = (int)$match[2];
				$day = (int)$match[3];

				// The year floor mirrors the editor too: there Date.UTC remaps a year below 100 into
				// the 1900s, the round-trip check catches the remap, and `0026` never becomes a node.
				return $year >= 100 && checkdate($month, $day, $year)
					? "{$match[1]}-{$match[2]}-{$match[3]}"
					: $match[0];
			},
			$text,
		);

		return $replaced ?? $text;
	}

	/**
	 * Replaces every escape sequence of the set with a placeholder, so the patterns above see the
	 * character as ordinary text and not as markdown syntax.
	 *
	 * Code spans and fenced blocks are copied through untouched: the editor does not escape inside
	 * them, so a backslash there is one the author typed (`C:\\temp` in a code span is a path, and
	 * collapsing it would change what the search shows). A backslash before anything outside the set
	 * is left alone for the same reason.
	 */
	private function protectEscapedCharacters(string $text): string
	{
		$source = str_replace(self::placeholders(), '', $text);
		if (!str_contains($source, '\\'))
		{
			return $source;
		}

		$length = strlen($source);
		$result = '';
		$offset = 0;
		$inFence = false;

		while ($offset < $length)
		{
			$char = $source[$offset];

			if ($char === '`')
			{
				// The whole run is consumed at once, opening or closing: leaving a part of it to the
				// next turn of the loop would make the remainder read as a code span of its own.
				$runLength = strspn($source, '`', $offset);
				$isFence = $runLength >= 3 && $this->isAtLineStart($source, $offset);

				if ($isFence)
				{
					$inFence = !$inFence;
					$result .= substr($source, $offset, $runLength);
					$offset += $runLength;
					continue;
				}

				if ($inFence)
				{
					$result .= substr($source, $offset, $runLength);
					$offset += $runLength;
					continue;
				}

				$run = substr($source, $offset, $runLength);
				$closing = strpos($source, $run, $offset + $runLength);
				// A run with no closing one is ordinary text, not a code span reaching the end of the
				// document: reading it as a span would leave the whole remainder escaped.
				$end = $closing === false ? $offset + $runLength : $closing + $runLength;
				$result .= substr($source, $offset, $end - $offset);
				$offset = $end;
				continue;
			}

			if ($inFence)
			{
				$result .= $char;
				$offset++;
				continue;
			}

			if ($char === '\\' && $offset + 1 < $length)
			{
				$index = array_search($source[$offset + 1], self::ESCAPED_CHARACTERS, true);
				if ($index !== false)
				{
					$result .= chr($index + 1);
					$offset += 2;
					continue;
				}
			}

			$result .= $char;
			$offset++;
		}

		return $result;
	}

	private function restoreEscapedCharacters(string $text): string
	{
		return str_replace(self::placeholders(), self::ESCAPED_CHARACTERS, $text);
	}

	/**
	 * @return string[]
	 */
	private static function placeholders(): array
	{
		return array_map(
			static fn (int $index): string => chr($index + 1),
			array_keys(self::ESCAPED_CHARACTERS),
		);
	}

	private function isAtLineStart(string $text, int $offset): bool
	{
		while ($offset > 0)
		{
			$previous = $text[$offset - 1];
			if ($previous === "\n")
			{
				return true;
			}
			if ($previous !== ' ' && $previous !== "\t")
			{
				return false;
			}
			$offset--;
		}

		return true;
	}
}
