<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

use Bitrix\Mail\Helper\Message;

/**
 * Prepares the text a message is classified by: the new part of the letter, capped in length.
 * Quoted history is dropped so the model does not judge a letter by the thread it carries.
 */
final class ClassificationTextBuilder
{
	/** Cap on the text handed to the model. */
	public const MAX_BODY_LENGTH = 16384;

	/** Cap on the raw body before conversion, and the same limit the database cuts a body by. */
	public const MAX_SOURCE_LENGTH = 65536;

	/**
	 * The same fact that puts UNSYNC_BODY in storage, not one that resembles it: the flag this answer sets
	 * is dropped by the deferred download pass alone, and that pass takes letters by UNSYNC_BODY. Hence the
	 * asymmetry - the html is asked the strict question saveMessage asks, the plain body whether there is
	 * any text to classify now. Markup blanked at save time (isStrippedTags) is refused the wait even
	 * though saveMessage writes UNSYNC_BODY for it as well: waiting would buy a second download of the
	 * same textless markup, so isBodyEmpty answers such a letter on the spot instead.
	 */
	public static function isBodyDeferred(array $message): bool
	{
		return (string)($message['BODY_HTML'] ?? '') === ''
			&& trim((string)($message['BODY'] ?? '')) === ''
			&& empty($message['OPTIONS']['isStrippedTags'])
		;
	}

	/**
	 * What htmlToTxt leaves of an image or a link when the markup around it carried no words: "[ url ]".
	 * Byte-wise like the rest of the patterns here - a body that is not valid utf-8 would break a unicode one.
	 */
	private const LINK_PLACEHOLDER_PATTERN = '#\[\s*(?:[a-z][a-z0-9+.\-]*:|www\.)\S+\s*\]#i';

	/**
	 * One-sided on purpose: markup that survives this answer may still convert to nothing, and only the
	 * converter knows that - too expensive for the receive loop, which has both bodies already loaded.
	 */
	public static function hasNothingToClassify(array $message): bool
	{
		return self::carriesNoWords((string)($message['BODY_HTML'] ?? ''))
			&& self::carriesNoWords((string)($message['BODY'] ?? ''))
		;
	}

	/**
	 * A letter of a single picture reads as "[ url ]" here, and its subject alone is not something this
	 * class classifies by - buildFrom answers empty for a subject without a body.
	 */
	private static function carriesNoWords(string $body): bool
	{
		if (trim($body) === '')
		{
			return true;
		}

		$stripped = preg_replace(self::LINK_PLACEHOLDER_PATTERN, ' ', $body);

		// A pcre refusal answers "there are words": the cheaper of the two errors is to classify a letter
		// that carried none, not to drop one that did.
		return $stripped !== null && trim($stripped) === '';
	}

	public static function buildBodyText(array $message): string
	{
		$html = (string)($message['BODY_HTML'] ?? '');
		if (trim($html) !== '')
		{
			$html = self::dropStyleAndScript(self::capSource($html));

			return self::capText(Message::normalizeBodyText(QuoteTrimmer::stripQuotedHtml($html)));
		}

		$plain = (string)($message['BODY'] ?? '');
		if (trim($plain) === '')
		{
			return '';
		}

		// The quote is dropped before normalization: normalizeBodyText collapses line breaks, and a
		// plain quote is found line by line.
		return self::capText(
			Message::normalizeBodyText(QuoteTrimmer::stripQuotedPlain(self::capSource($plain))),
		);
	}

	public static function compose(string $subject, string $bodyText): string
	{
		return trim($subject . "\n\n" . $bodyText);
	}

	/**
	 * @return string Empty when the message carries no text to classify.
	 */
	public static function buildFrom(array $message): string
	{
		$bodyText = self::buildBodyText($message);

		return $bodyText === '' ? '' : self::compose((string)($message['SUBJECT'] ?? ''), $bodyText);
	}

	private static function capSource(string $body): string
	{
		return mb_substr($body, 0, self::MAX_SOURCE_LENGTH);
	}

	/**
	 * Cuts by a word boundary, the way Message::extractSubjectFromBody does.
	 */
	private static function capText(string $text): string
	{
		if (mb_strlen($text) <= self::MAX_BODY_LENGTH)
		{
			return $text;
		}

		$trimmed = mb_substr($text, 0, self::MAX_BODY_LENGTH);
		$lastSpace = mb_strrpos($trimmed, ' ');

		if ($lastSpace !== false && $lastSpace > 0)
		{
			return mb_substr($trimmed, 0, $lastSpace);
		}

		return $trimmed;
	}

	/**
	 * Dropped here and not left to Converter::htmlToText: the converter removes them only as pairs, so a
	 * block left open - by a broken letter or by our own cap - leaks css or code into the text as words.
	 */
	private static function dropStyleAndScript(string $html): string
	{
		$pattern = '#<(?:style|script)\b[^>]*>.*?(?:(?=<(?:/|!|[a-z][a-z0-9]*[\s/>]))|$)#is';

		return preg_replace($pattern, ' ', $html) ?? $html;
	}
}
