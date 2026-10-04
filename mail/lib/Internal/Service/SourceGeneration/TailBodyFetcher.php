<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper;
use Bitrix\Mail\MailMessageTable;

/**
 * The body of a letter of the tail, downloaded from the source still serving the mailbox
 * and written into the row we keep of that letter.
 *
 * Some letters are kept as an envelope alone: the subject, the addresses and the date, and
 * neither the text nor the markup of what was written. The save path leaves such a row
 * behind two ways - a letter saved by the lazy route whose body never downloaded
 * afterwards, and a save that broke off half way through. Assembled out of that row, the
 * letter reaches the new source as an empty shell.
 *
 * So the body is fetched while it still can be, for the reason the attachments are
 * ({@see TailAttachmentFetcher}): the old source is the only place it exists, and once the
 * mailbox has been handed over a download through a placement of the retained generation is
 * refused by the boundary of the engine. It is worth having whether the append succeeds or
 * not - a body in our row is a body the deal in CRM, the search and every screen can read.
 *
 * What this is NOT is a delivery, and not the deferred download either. The lazy route of
 * the reading screens ({@see Helper\Message::reSyncBody()}) does the very same download and
 * closes the wait around it: it drops the mark of a body still expected and publishes the
 * event the classifier of the letters listens to. Neither belongs here. The wait is not
 * over - the letter is read back off the new source after the switch and the ordinary route
 * completes it there, exactly as it would have without this stage - and a letter years old
 * is not one to queue for classification. What is written is the two columns of the body,
 * and nothing else is touched.
 */
class TailBodyFetcher
{
	/**
	 * Gives the letter the body it never received.
	 *
	 * @param Helper\Mailbox|null $engine The engine of the generation serving the mailbox,
	 *        built once for the whole pass; null when that source cannot be reached at all.
	 * @param array $message A row of b_mail_message with the coordinates of the archived
	 *        placement joined in: ID, MAILBOX_ID, BODY, BODY_HTML, SANITIZE_ON_VIEW,
	 *        ATTACHMENTS, DIR_MD5, MSG_UID, GENERATION_ID. Left with the body that arrived.
	 * @return bool False when the letter stays the envelope it was: the source did not hand
	 *         a body over, or the letter really has none there either. Such a letter is
	 *         appended all the same - an envelope on the new source keeps it in the list, in
	 *         the search and in the thread of the switched mailbox, while holding it back
	 *         would cost the mailbox its whole tail over a body that may be gone for good -
	 *         and the loss is counted apart ({@see MigrationMetrics::ENVELOPE_ONLY}).
	 */
	public function fetch(?Helper\Mailbox $engine, array &$message): bool
	{
		if (!$this->isPending($message))
		{
			return true;
		}

		$mime = $engine === null ? false : $engine->downloadMessage($message);

		if (!is_string($mime) || $mime === '')
		{
			$this->report($message, 'the source did not hand it over');

			return false;
		}

		[, $html, $text] = \CMailMessage::parseMessage($mime, $this->charsetOf($engine));

		if (\CMailMessage::isLongMessageBody($text))
		{
			// The quoted conversation below the marker is cut off, as the save path cuts it
			[$text, $html] = \CMailMessage::prepareLongMessage($text, $html);
		}

		$text = rtrim((string)$text);
		$html = (string)$html;

		if ($text === '' && $html === '')
		{
			// The letter is an envelope on the server as well, so there is nothing to write
			$this->report($message, 'the letter of that source carries no body either');

			return false;
		}

		$this->store($message, $text, $html);

		return true;
	}

	/**
	 * Whether the letter is one of those kept as an envelope: neither of its two bodies, and
	 * no file of its own to speak for it either.
	 *
	 * The files are why the question is asked in that order. A letter carrying them is one
	 * the save path really did read, so the body it has is the body it had; and a whole
	 * message downloaded for it would cost the pass another round trip per letter over the
	 * one the files already cost. The letter asked about here has nothing at all.
	 *
	 * Asked by the caller as well, before it opens a connection to that source: a pass over
	 * letters that have their bodies needs no such connection.
	 */
	public function isPending(array $message): bool
	{
		return (string)($message['BODY'] ?? '') === ''
			&& (string)($message['BODY_HTML'] ?? '') === ''
			&& (int)($message['ATTACHMENTS'] ?? 0) <= 0
		;
	}

	/**
	 * The charset the columns of this mailbox are spelled in, as every path parsing a
	 * downloaded message reads it.
	 */
	private function charsetOf(Helper\Mailbox $engine): string
	{
		$mailbox = $engine->getMailbox();

		return (string)(($mailbox['CHARSET'] ?? '') ?: ($mailbox['LANG_CHARSET'] ?? 'utf-8'));
	}

	/**
	 * The two columns of the body, written the way the save path of this portal writes them:
	 * markup of a message stored without the sanitizer of the read path is cleaned up now,
	 * because the flag saying which of the two it is stays what it was.
	 */
	private function store(array &$message, string $text, string $html): void
	{
		if ($html !== '' && empty($message[MailMessageTable::FIELD_SANITIZE_ON_VIEW]))
		{
			$html = Helper\Message::sanitizeHtmlForMessageView($html);
		}

		$message['BODY'] = $text;
		$message['BODY_HTML'] = $html;

		\CMailMessage::update(
			(int)($message['ID'] ?? 0),
			['BODY' => $text, 'BODY_HTML' => $html],
			(int)($message['MAILBOX_ID'] ?? 0),
		);
	}

	private function report(array $message, string $reason): void
	{
		AddMessage2Log(
			sprintf(
				'The body of the message %u of the mailbox %u is not there for the tail append: %s',
				(int)($message['ID'] ?? 0),
				(int)($message['MAILBOX_ID'] ?? 0),
				$reason,
			),
			'mail',
			2,
			false,
		);
	}
}
