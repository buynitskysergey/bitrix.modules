<?php

namespace Bitrix\Crm\Activity\Mail;

class EmailQuoteStripper
{
	private static array $outlookQuoteMarkers = [
		'id="divRplyFwdMsg"',
		'id=\'divRplyFwdMsg\'',
		'class="WordSection1"',
		'class=\'WordSection1\'',
	];

	public static function strip(string $html): string
	{
		if ($html === '')
		{
			return '';
		}

		\Bitrix\Main\Config\Ini::adjustPcreBacktrackLimit(strlen($html) * 2);

		$stripped = self::stripByDomPatterns($html);

		$text = self::htmlToPlainText($stripped);
		$text = self::stripSignatures($text);
		$text = self::normalizeWhitespace($text);

		return $text;
	}

	private static function stripByDomPatterns(string $html): string
	{
		$result = self::stripGmailQuote($html);
		if ($result !== $html)
		{
			return $result;
		}

		$result = self::stripOutlookQuote($html);
		if ($result !== $html)
		{
			return $result;
		}

		$result = self::stripBlockquote($html);

		return $result;
	}

	private static function stripGmailQuote(string $html): string
	{
		if (preg_match(
			'/<div\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bgmail_(?:quote|extra)\b[^"\']*\1[^>]*>/iu',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE,
		))
		{
			$pos = $matches[0][1];
			if ($pos > 0)
			{
				return substr($html, 0, $pos);
			}
		}

		return $html;
	}

	private static function stripOutlookQuote(string $html): string
	{
		foreach (self::$outlookQuoteMarkers as $marker)
		{
			foreach (['<div', '<td', '<p'] as $tag)
			{
				$search = $tag . ' ' . $marker;
				$pos = mb_stripos($html, $search);
				if ($pos !== false)
				{
					if ($pos === 0)
					{
						return $html;
					}

					return mb_substr($html, 0, $pos);
				}
			}
		}

		$separatorPatterns = [
			'/(-----Original Message-----|---+\s*Original Message\s*---+)/isu',
			'/(<br[^>]*>\s*){1,3}\s*(From|От):\s*[^\r\n]+(<br[^>]*>|<\/p>)/isu',
		];

		foreach ($separatorPatterns as $pattern)
		{
			if (preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE))
			{
				$byteOffset = $matches[0][1];
				if ($byteOffset > 0)
				{
					return substr($html, 0, $byteOffset);
				}
			}
		}

		return $html;
	}

	private static function stripBlockquote(string $html): string
	{
		$pos = mb_stripos($html, '<blockquote');
		if ($pos === false)
		{
			return $html;
		}

		$beforeQuote = mb_substr($html, 0, $pos);

		$text = self::htmlToPlainText($beforeQuote);
		$text = self::normalizeWhitespace($text);

		if (mb_strlen(trim($text)) < 10)
		{
			return $html;
		}

		return $beforeQuote;
	}

	private static function htmlToPlainText(string $html): string
	{
		$text = preg_replace(['/<p[^>]*>/isu', '/<\/p[^>]*>/isu'], ['', "\n\n"], $html) ?? $html;
		$text = preg_replace('/(<br[^>]*>)+/isu', "\n", $text) ?? $text;
		$text = preg_replace('/<\/?(div|tr)[^>]*>/isu', "\n", $text) ?? $text;
		$text = preg_replace('/<[^>]+>/s', '', $text) ?? $text;
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return $text;
	}

	private static function stripSignatures(string $text): string
	{
		$lines = preg_split('/\r?\n/', $text);
		if ($lines === false)
		{
			return $text;
		}

		$cutLine = null;

		foreach ($lines as $i => $line)
		{
			$trimmed = trim($line);

			if ($trimmed === '--' || $trimmed === '-- ')
			{
				$cutLine = $i;
				break;
			}

			if (self::looksLikeQuoteMarker($trimmed))
			{
				$cutLine = $i;
				break;
			}
		}

		if ($cutLine !== null && $cutLine > 0)
		{
			$beforeCut = array_slice($lines, 0, $cutLine);
			$beforeText = trim(implode("\n", $beforeCut));
			if ($beforeText !== '')
			{
				return $beforeText;
			}
		}

		return $text;
	}

	private static function looksLikeQuoteMarker(string $line): bool
	{
		if ($line === '')
		{
			return false;
		}

		$quoteLinePatterns = [
			'/^(?:On\s+)?\d{2}\.\d{2}\.\d{4}\s+.+\s+пишет:$/iu',
			'/^\d{2}\.\d{2}\.\d{4}.+?:$/iu',
			'/^>{1,}/u',
			'/^-{4,}(Forwarded message|-{4,})/iu',
			'/^_{5,}$/u',
		];

		foreach ($quoteLinePatterns as $pattern)
		{
			if (preg_match($pattern, $line))
			{
				return true;
			}
		}

		return false;
	}

	private static function normalizeWhitespace(string $text): string
	{
		$text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
		$text = preg_replace('/(\r?\n){3,}/', "\n\n", $text) ?? $text;

		return trim($text);
	}
}
