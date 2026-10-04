<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration\Matching;

use Bitrix\Mail\Helper;

/**
 * The single canonicalization of a message (ALG-01).
 *
 * It turns both sides of the matching into one versioned structure:
 *  - a message already stored by a previous generation, taken from its saved
 *    columns and its available decoded attachments;
 *  - a MIME fetched and parsed in memory by the generation being imported.
 *
 * The class is pure: no database, no network, no state of its own - the kernel is
 * only asked for the very conversions the save path uses, so that a value reaches
 * the comparison through the same code on both sides. Callers pass the header values
 * and the text the save path itself would receive, which is what keeps the two sides
 * comparable.
 *
 * It never assumes the original MIME survived in a stored message: the body may
 * have been prepared as a long one, the HTML may have been cleared before the
 * insert or left for the sanitizer of the read path, and SQL fields may have been
 * cut to the size the database accepts. A transformation that cannot be reproduced
 * is kept out of the indexed payload and confirmed separately; the cut to the width
 * of a column is reproduced instead, because it depends on nothing but the value.
 */
final class CanonicalMessageNormalizer
{
	/**
	 * The canonicalization version. It becomes part of the fingerprint index and
	 * of the stored matching result, so a future change of the rules below never
	 * compares two different canonical shapes.
	 */
	public const VERSION = 2;

	/**
	 * The width of every text column a stored message keeps its envelope in: the subject,
	 * the address fields, the identifier of the letter and the name of an attachment. A
	 * longer value reaches the stored side already cut - by the save path for the subject,
	 * by the column itself for the rest - so the incoming side is cut the same way, and it
	 * is the cut value that goes on to be hashed into the indexed fingerprint.
	 */
	private const COLUMN_LIMIT = 255;

	/** The one charset the entities of a markup are decoded in, whichever side it came from */
	private const ENTITY_CHARSET = 'UTF-8';

	/** Both spellings of the link of an inline attachment: the row of the portal and the part of a MIME */
	private const ATTACHMENT_LINK_REGEX = '~(?:https?://)?\b[ac]id:[^\s"\'>]+~i';

	private const ATTACHMENT_LINK_LABEL = 'mail-attachment';

	private const ADDRESS_REGEX = '/[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@[a-z0-9-]+(?:\.[a-z0-9-]+)+/i';

	/**
	 * A message stored by a previous generation.
	 *
	 * @param array $message A b_mail_message row.
	 * @param string[] $attachmentNames Names of the attachments already decoded and saved.
	 * @param bool $bodyIsComplete False when the body of that message is still missing (UNSYNC_BODY).
	 */
	public function fromStoredMessage(array $message, array $attachmentNames = [], bool $bodyIsComplete = true): CanonicalMessageData
	{
		$options = $message['OPTIONS'] ?? [];
		$attachmentCount = is_array($options) && isset($options['attachments'])
			? (int)$options['attachments']
			: count($attachmentNames)
		;

		return $this->build(
			messageId: (string)($message['MSG_ID'] ?? ''),
			subject: (string)($message['SUBJECT'] ?? ''),
			from: (string)($message['FIELD_FROM'] ?? ''),
			to: (string)($message['FIELD_TO'] ?? ''),
			cc: (string)($message['FIELD_CC'] ?? ''),
			date: $this->extractDateHeader((string)($message['HEADER'] ?? '')),
			bodyText: (string)($message['BODY'] ?? ''),
			bodyHtml: (string)($message['BODY_HTML'] ?? ''),
			attachmentNames: $attachmentNames,
			attachmentCount: $attachmentCount,
			bodyIsComplete: $bodyIsComplete,
		);
	}

	/**
	 * A MIME fetched by the generation being imported, parsed but not saved yet.
	 *
	 * Everything the save path would cut on the way to its column is cut here, in the very
	 * order that path does it: the identifier loses its angle brackets first and is cut
	 * afterwards, and an address field is cut as the raw line of the header, before the
	 * addresses are parsed out of it - the column cuts that line, and an address it cut in
	 * half is no longer recognized as an address at all.
	 *
	 * @param array $headers Header values as the save path reads them: MESSAGE-ID, SUBJECT, FROM, TO, CC, DATE.
	 * @param string[] $attachmentNames Names of the attachment parts of that MIME.
	 */
	public function fromIncomingMime(
		array $headers,
		string $bodyHtml,
		string $bodyText,
		array $attachmentNames,
		?int $attachmentCount = null,
		array $countVariants = [],
	): CanonicalMessageData
	{
		return $this->build(
			messageId: $this->cutToColumn(trim((string)($headers['MESSAGE-ID'] ?? ''), ' <>')),
			subject: $this->cutToColumn((string)($headers['SUBJECT'] ?? '')),
			from: $this->cutToColumn((string)($headers['FROM'] ?? '')),
			to: $this->cutToColumn((string)($headers['TO'] ?? '')),
			cc: $this->cutToColumn((string)($headers['CC'] ?? '')),
			date: (string)($headers['DATE'] ?? ''),
			bodyText: $bodyText,
			bodyHtml: $bodyHtml,
			attachmentNames: array_map($this->cutToColumn(...), $attachmentNames),
			attachmentCount: $attachmentCount ?? count($attachmentNames),
			bodyIsComplete: true,
			countVariants: $countVariants,
		);
	}

	/**
	 * @param string[] $attachmentNames
	 */
	private function build(
		string $messageId,
		string $subject,
		string $from,
		string $to,
		string $cc,
		string $date,
		string $bodyText,
		string $bodyHtml,
		array $attachmentNames,
		int $attachmentCount,
		bool $bodyIsComplete,
		array $countVariants = [],
	): CanonicalMessageData
	{
		$body = $this->normalizeBody($bodyText, $bodyHtml);

		$names = array_map($this->normalizeText(...), $attachmentNames);
		sort($names);

		return new CanonicalMessageData(
			version: self::VERSION,
			messageId: $this->normalizeMessageId($messageId),
			subject: $this->normalizeText($subject),
			from: $this->normalizeAddresses($from),
			to: $this->normalizeAddresses($to),
			cc: $this->normalizeAddresses($cc),
			date: $this->normalizeDate($date),
			attachmentCount: max($attachmentCount, 0),
			attachmentNames: $names,
			body: $body,
			hasBody: $bodyIsComplete && $body !== '',
			countVariants: array_values(array_unique(array_map('intval', $countVariants))),
		);
	}

	private function cutToColumn(string $value): string
	{
		return mb_substr($value, 0, self::COLUMN_LIMIT);
	}

	private function normalizeMessageId(string $value): string
	{
		return mb_strtolower(trim($value, " \t\r\n<>"));
	}

	/**
	 * @return string[] Unique addresses of a header field, lowercased and ordered.
	 */
	private function normalizeAddresses(string $value): array
	{
		if (!preg_match_all(self::ADDRESS_REGEX, $value, $matches))
		{
			return [];
		}

		$addresses = array_unique(array_map(mb_strtolower(...), $matches[0]));
		sort($addresses);

		return array_values($addresses);
	}

	/**
	 * The moment the message was written, as a UTC timestamp. Two sources may
	 * spell the same moment in different time zones, so only the instant counts.
	 */
	private function normalizeDate(string $value): int
	{
		$value = trim($value);
		if ($value === '')
		{
			return 0;
		}

		// The obsolete UT zone the save path also repairs before reading the date
		$value = preg_replace('/(?<=[\s\d])UT$/i', '+0000', $value);

		return (int)(strtotime($value) ?: 0);
	}

	/**
	 * The date of a stored message, read out of its header block by the very parser the
	 * incoming side is read by. A letter relayed or sent again carries two date headers,
	 * and the parser keeps the last of them: a reading of its own would take the first one
	 * and call the two sides of one letter different letters.
	 */
	private function extractDateHeader(string $header): string
	{
		if (trim($header) === '')
		{
			return '';
		}

		return (string)\CMailMessage::parseHeader($header, 'UTF-8')->getHeader('DATE');
	}

	/**
	 * The text of the message. HTML is reduced to its text by the very conversion the
	 * save path and the read of an incoming MIME both use, so a representation that
	 * kept no text of its own reaches the value the text part of the same letter has.
	 * Dropping the markup without it would glue the blocks of the HTML into one word.
	 */
	private function normalizeBody(string $bodyText, string $bodyHtml): string
	{
		if (trim($bodyText) === '' && $bodyHtml !== '')
		{
			$bodyText = $this->textOfMarkup($bodyHtml);
		}

		return $this->normalizeText($bodyText);
	}

	/**
	 * The text a message carries when its markup is all it kept. Not one of the steps below
	 * is symmetric on its own: the link of an inline image is an attachment row of the
	 * portal in a stored markup and the content id of a part in a fetched one, and the
	 * sanitizer of the read path has met a stored markup and never a fetched one - while
	 * the conversion puts that link into the text it builds.
	 */
	private function textOfMarkup(string $html): string
	{
		$html = $this->maskAttachmentLinks($html);
		$html = $this->sanitize($html);

		return html_entity_decode(htmlToTxt($html), ENT_QUOTES | ENT_HTML401, self::ENTITY_CHARSET);
	}

	/**
	 * Both spellings of the link of an inline attachment reduced to one label. It also frees
	 * the text of the identifier of an attachment row, which is another one in another
	 * generation of the same letter.
	 */
	private function maskAttachmentLinks(string $html): string
	{
		return preg_replace(self::ATTACHMENT_LINK_REGEX, self::ATTACHMENT_LINK_LABEL, $html) ?? $html;
	}

	/**
	 * The sanitizer of the read path, run on both sides. It is idempotent, so a markup that
	 * has already been through it on the save path stays as it is.
	 *
	 * A markup of a broken encoding comes back empty, and two letters emptied that way would
	 * be one and the same letter by their bodies - the one thing a body must never allow.
	 */
	private function sanitize(string $html): string
	{
		$sanitized = Helper\Message::sanitizeHtmlForMessageView($html);

		return trim($sanitized) === '' ? $html : $sanitized;
	}

	private function normalizeText(string $value): string
	{
		$value = $this->repairEncoding($value);
		$value = preg_replace('/\s+/u', ' ', $value) ?? $value;

		return mb_strtolower(trim($value));
	}

	/**
	 * A stored value cut to the size the database accepts is cut by bytes, so it can end in
	 * the middle of a character. Every unicode aware operation below refuses such a string
	 * and returns nothing at all, which would leave one side of the comparison unfolded.
	 */
	private function repairEncoding(string $value): string
	{
		return preg_match('//u', $value) === 1
			? $value
			: (string)mb_convert_encoding($value, 'UTF-8', 'UTF-8')
		;
	}
}
