<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

/**
 * Drops the quoted history: a heuristic and not a promise - it knows the quote block of markup, the prefix
 * markers of plain text and the separators clients write above a quoted thread, and nothing else.
 */
class QuoteTrimmer
{
	/**
	 * Separators are recognised by this list and not by shape: a line of dashes around a couple of words is
	 * something a letter writes about itself often enough, and cutting a letter there is the more expensive
	 * of the two mistakes. A separator the list does not know leaves the letter as it is.
	 *
	 * Compared after strtolower, which works byte by byte: the ascii entries match in any case, the cyrillic
	 * ones only in the case clients actually write.
	 */
	private const HISTORY_SEPARATORS = [
		'original message',
		'original appointment',
		'forwarded message',
		'Исходное сообщение',
		'Пересылаемое сообщение',
	];

	public static function stripQuotedHtml(string $html): string
	{
		if ($html === '')
		{
			return $html;
		}

		if (preg_match('/<blockquote\b/i', $html, $matches, PREG_OFFSET_CAPTURE) !== 1)
		{
			return $html;
		}

		$before = substr($html, 0, (int)$matches[0][1]);

		// The guard asks for the text of what is left, not for its markup: a wrapper element the client
		// puts before the quote is not a letter.
		return self::hasText($before) ? $before : $html;
	}

	public static function stripQuotedPlain(string $plain): string
	{
		if ($plain === '')
		{
			return $plain;
		}

		$body = str_replace("\r\n", "\n", $plain);
		$stripped = self::dropQuotedHistory($body);

		// Nothing was found: answer with the argument itself, so a caller comparing the two learns that the
		// letter is untouched rather than merely re-lined.
		if ($stripped === $body)
		{
			return $plain;
		}

		$stripped = rtrim($stripped);

		// The letter turned out to be history all the way down. Handing that over means handing over
		// nothing, so the letter goes with its quote - the cheaper of the two mistakes.
		return $stripped === '' ? $plain : $stripped;
	}

	private static function hasText(string $html): bool
	{
		return trim(strip_tags($html)) !== '';
	}

	/**
	 * The history is taken out block by block instead of cutting the body at the first quote: a signature
	 * below the quote, and an answer written under the question it answers, are both ordinary letters, and
	 * both used to keep the whole thread in frame.
	 *
	 * One pass over the lines and no regular expression at all. A long thread is exactly the input that made
	 * pcre give up and report the failure as "no match", so a pattern here is not worth the silence it can
	 * return.
	 */
	private static function dropQuotedHistory(string $body): string
	{
		$kept = [];
		$quotedRun = [];

		foreach (explode("\n", $body) as $line)
		{
			if (self::isQuotedLine($line))
			{
				$quotedRun[] = $line;

				continue;
			}

			// A lone quoted line is how a letter cites a phrase inside its own text, so history is a run of
			// two or more. A blank line between two quoted lines breaks the run for the same reason.
			if (count($quotedRun) === 1)
			{
				$kept[] = $quotedRun[0];
			}
			$quotedRun = [];

			// Below the separator the client wrote the thread it quotes, down to the end of the body.
			if (self::isHistorySeparator($line))
			{
				return implode("\n", $kept);
			}

			$kept[] = $line;
		}

		if (count($quotedRun) === 1)
		{
			$kept[] = $quotedRun[0];
		}

		return implode("\n", $kept);
	}

	private static function isQuotedLine(string $line): bool
	{
		$trimmed = ltrim($line);

		return $trimmed !== '' && $trimmed[0] === '>';
	}

	private static function isHistorySeparator(string $line): bool
	{
		$trimmed = trim($line);

		// The dashes are what tells a separator from a letter naming the same words.
		if (!str_starts_with($trimmed, '--'))
		{
			return false;
		}

		return in_array(strtolower(trim($trimmed, '- ')), self::HISTORY_SEPARATORS, true);
	}
}
