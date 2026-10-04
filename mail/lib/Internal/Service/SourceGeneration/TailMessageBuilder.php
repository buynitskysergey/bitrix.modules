<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Mail;
use Bitrix\Main\Mail\Multipart;
use Bitrix\Main\Mail\Part;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;

/**
 * The MIME of a message the tail append stage puts back on the new server, assembled out
 * of the columns of our own database.
 *
 * The assembler of the kernel is written for SENDING a message that is being composed
 * right now. Here it restores an INCOMING message received years ago, so it has to be
 * talked out of eleven of its habits - and every one of them is talked out here, in this
 * one class. Taken apart each is a triviality;
 * scattered over the code they are a mine for whoever edits the assembly next. One place
 * makes the border a declared one: this is where, and why, the stage parts with the
 * behaviour of the kernel.
 *
 * What is restored: both bodies, the subject, the envelope, the date of the sender, the
 * attachments with their names and the links from the markup to the inline images.
 *
 * What is lost for good and is named in the description of the update: the service
 * headers (Received, the signature of the domain, the references to the previous
 * messages, any X-*) - the stored header block is their only source and is the first
 * thing the save path cuts; the original text part when we never had one; the original
 * boundaries, the order and the encodings of the parts; the subject and the list of the
 * recipients past the width of their columns; the quoted tail of a long message.
 */
final class TailMessageBuilder extends Mail
{
	/** The width of the columns the envelope of a message is kept in */
	private const COLUMN_LIMIT = 255;

	/**
	 * The transfer encoding of every part written here, ours and not the portal's. The
	 * kernel takes it from portal options - 8bit by default - while the append command
	 * sends the message as a literal without asking whether the server accepts 8bit
	 * octets at all. Left to the options, one and the same message would leave two
	 * portals differently spelled and some servers would refuse it. Base64 carries any
	 * bytes and asks the server for nothing.
	 */
	private const PART_ENCODING = 'base64';

	/**
	 * The headers the send path of the module puts on a message it uploads. A restored
	 * message carries neither, whatever a caller passes.
	 *
	 * X-Bitrix-Mail-Message-UID is the dangerous one: the reverse read takes it in any
	 * generation, declares the message outgoing and updates the placement row it names
	 * in place - the lookup carries no condition on the generation and the updated fields
	 * hold no generation either. The row it names is the archived one, so the archive
	 * would be overwritten with the coordinates of the new copy and the matcher would
	 * never hear of the message.
	 */
	private const FORBIDDEN_HEADERS = ['x-bitrix-mail-message-uid', 'x-mid'];

	/** The text part of the message, as our own column keeps it */
	private string $textBody = '';

	/** Names of the attachments whose file is no longer in our own storage */
	private array $missingAttachments = [];

	/**
	 * @param array $mailParams Parameters of the assembler of the kernel.
	 * @param string $textBody The text part of the message, as our column keeps it. Read by
	 *                         setBody() below, which the parent constructor runs - so it is
	 *                         assigned before that constructor is called.
	 */
	public function __construct(array $mailParams, string $textBody = '')
	{
		$this->textBody = $textBody;

		parent::__construct($mailParams);
	}

	/**
	 * @param array $message A row of b_mail_message: SUBJECT, BODY, BODY_HTML, HEADER,
	 *                       MSG_ID, IN_REPLY_TO and the FIELD_* columns of the envelope.
	 * @param array $attachments Rows of b_mail_msg_attachment: ID, FILE_ID, FILE_NAME,
	 *                           CONTENT_TYPE.
	 * @param DateTime|null $receivedAt The stamp the old server put on the archived
	 *                                  placement. Used for the Date header only when the
	 *                                  stored header block no longer holds one.
	 * @param string $tailMark The one time mark of the fallback recognition, and only for a
	 *                         source that answers no coordinates to an append: a letter whose
	 *                         coordinates we learn needs no mark, and putting one into it
	 *                         would leave a mark of ours inside a letter of the client for
	 *                         nothing ({@see TailMarkService}).
	 */
	public static function fromStoredMessage(
		array $message,
		array $attachments = [],
		?DateTime $receivedAt = null,
		string $tailMark = '',
	): self
	{
		$charset = self::portalCharset();
		$header = self::parseStoredHeader((string)($message['HEADER'] ?? ''), $charset);

		/*
			Every column is decoded, without asking which of them the read of the ORM has
			decoded already. The modificator sits on three fields only - the sender, the
			subject and the markup - while the text body, all four recipient fields and the
			names of the attachments have none. Decoding what is already a character is
			harmless, since a real text holds no markers; leaving a marker in place is not,
			and it shows most in the subject, which a human sees in the list of messages.
			Comparing a column against its encoded spelling would be wrong either way: the
			encoding on write is incomplete, so the columns hold a mix of both forms.
		*/
		$html = Emoji::decode((string)($message['BODY_HTML'] ?? ''));
		$text = Emoji::decode((string)($message['BODY'] ?? ''));
		$subject = Emoji::decode((string)($message['SUBJECT'] ?? ''));

		[$attachmentParams, $html, $missing] = self::attachmentParts(
			$attachments,
			(string)($message['MSG_ID'] ?? ''),
			$html,
		);

		$to = self::addressHeader($message, 'FIELD_TO', 'TO', $header, $charset);

		$headers = [
			'Date' => self::dateHeader($header, $receivedAt),
			'From' => self::addressHeader($message, 'FIELD_FROM', 'FROM', $header, $charset),
			'To' => $to,
			'Cc' => self::addressHeader($message, 'FIELD_CC', 'CC', $header, $charset),
			'Bcc' => self::addressHeader($message, 'FIELD_BCC', 'BCC', $header, $charset),
			'Reply-To' => self::addressHeader($message, 'FIELD_REPLY_TO', 'REPLY-TO', $header, $charset),
			'Subject' => $subject,
			'Message-ID' => self::bracketed((string)($message['MSG_ID'] ?? '')),
			'In-Reply-To' => self::bracketed((string)($message['IN_REPLY_TO'] ?? '')),
			/*
				Restored when the stored block really carried it, never invented: the kernel
				writes "3 (Normal)" of its own into a message that never had a priority.
			*/
			'X-Priority' => trim((string)$header->getHeader('X-PRIORITY')),
			// The kernel used to add it past the headers we pass; it is spelled out here instead
			'MIME-Version' => '1.0',
			TailMarkService::HEADER => $tailMark,
		];

		$builder = new self(
			[
				'CHARSET' => $charset,
				'CONTENT_TYPE' => 'html',
				'ATTACHMENT' => $attachmentParams,
				'TO' => $to,
				'SUBJECT' => $subject,
				'BODY' => $html,
				'HEADER' => $headers,
				/*
					No identifier of a mail event is passed: with one the kernel would append
					an "MID #<id>" line to the body and an X-MID header - a line the original
					message never had, seen by a human and enough to part the bodies when the
					fallback route has to compare them.
				*/
			],
			$text,
		);

		$builder->missingAttachments = $missing;

		return $builder;
	}

	/**
	 * The message as the append command sends it: the header block, an empty line, the body.
	 */
	public function getRawMessage(): string
	{
		return $this->getHeaders() . $this->eol . $this->eol . $this->getBody();
	}

	/**
	 * @return string[] Names of the attachments left out because their file is gone from our
	 *                  storage. The message goes up without them - never with a stub instead.
	 */
	public function missingAttachments(): array
	{
		return $this->missingAttachments;
	}

	public function initSettings(): void
	{
		parent::initSettings();

		/*
			The size limit of outgoing mail does not apply to this stage. Past the ACCUMULATED
			limit the kernel replaces an attachment - and
			every attachment after it - with a text stub named "<name>.txt" saying the
			original was too large. The limit is there so that a NEW message still leaves the
			portal when the far server refuses one that big; here the message and its files
			already exist, no recipient is waiting, and what would land in the mailbox of the
			client is a plausible forgery: the true date, sender and subject, and a note
			instead of the contract. Everyone reading that mailbox outside the portal sees it,
			the next migration and every backup of the provider copy it on, and a file lost
			from our own storage could no longer be restored from the server. Zero switches
			the check off - it only fires above zero.
		*/
		$this->settingMaxFileSize = 0;

		/*
			No blacklist. filterHeaderEmails() silently drops blacklisted addresses out of the
			recipients and removes a header it emptied altogether, so the recipient list of a
			message three years old would depend on the blacklist of the portal today.
		*/
		$this->useBlacklist = false;
	}

	/**
	 * CRLF whatever the platform reports: what is assembled here goes on the wire as the
	 * literal of an append command, not into a local sendmail.
	 */
	public static function getMailEol(): string
	{
		return "\r\n";
	}

	/**
	 * Both bodies of the message, each from the column that keeps it.
	 *
	 * The kernel builds a composite message from the markup alone: it GENERATES the text
	 * part by running the markup through the html-to-text converter, and its only
	 * alternative - switching the generation off - drops the text part instead of taking
	 * one. There is no input for a text part of our own, so the assembly happens here.
	 * With a retold markup in place of the text the fallback route of the matching would
	 * part the restored copy from the row already stored and the message would lose its
	 * history - the very harm this stage exists against; and the retelling is what those
	 * who show the text and not the markup would read.
	 *
	 * Four edits of the kernel are skipped along with it, and that is the point, because
	 * all four rewrite the body of a message that already exists: replaceHrefs()/
	 * trackClick() turn every link of the markup into a portal one as soon as the portal
	 * has a server name, getReplacedImageSrc() does the same to the sources of the
	 * images, trackRead() appends a tracking pixel and addMessageIdToBody() appends the
	 * service line with the identifier of a mail event.
	 *
	 * @param string $bodyPart The markup of the message.
	 */
	public function setBody($bodyPart)
	{
		$html = (string)$bodyPart;

		/*
			A message that kept neither body is still assembled - as an envelope. By the time
			it gets here the stage has already offered it the source it arrived from
			({@see TailBodyFetcher}), so an envelope means a body nobody has anymore.
		*/
		$plainPart = $this->textPart('text/plain', $this->textBody);
		$htmlPart = $html === '' ? null : $this->textPart('text/html', $html);

		if ($htmlPart !== null && $this->hasImageAttachment(true))
		{
			$this->multipartRelated = (new Multipart())->setContentType(Multipart::RELATED)->setEol($this->eol);
			$this->multipartRelated->addPart($htmlPart);
			$htmlPart = $this->multipartRelated;
		}

		if ($htmlPart === null)
		{
			$this->multipart->addPart($plainPart);
		}
		elseif ($this->textBody === '')
		{
			$this->multipart->addPart($htmlPart);
		}
		else
		{
			$alternative = (new Multipart())->setContentType(Multipart::ALTERNATIVE)->setEol($this->eol);
			$alternative->addPart($plainPart);
			$alternative->addPart($htmlPart);
			$this->multipart->addPart($alternative);
		}

		/*
			The transfer encoding above went on the parts and on none of the containers, though
			the kernel writes it on both: base64 on a multipart tells a reader to decode the
			container instead of the parts inside it.
		*/
		$this->setAttachment();

		// Line endings of the whole assembly brought to CRLF, as a message on the wire has them
		$this->body = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $this->multipart->toStringBody());
	}

	/**
	 * The header block of the restored message: the headers it really had, and no others.
	 *
	 * The kernel invents four of its own on the way out - Reply-To copied from the sender,
	 * X-Priority "3 (Normal)", Date set to the current time and MIME-Version. The first
	 * three are restored by the factory from what we stored, or left out; the fourth is
	 * spelled out there as well. Gone with the parent are the blacklist filter of the
	 * recipients, the envelope rewrites of the MS SMTP mode, the To filled in from the
	 * recipient of the send and the X-MID line.
	 */
	public function getHeaders(): string
	{
		$result = '';

		foreach ($this->headers as $name => $value)
		{
			$value = trim((string)$value, "\r\n");

			if ($value === '' || in_array(mb_strtolower((string)$name), self::FORBIDDEN_HEADERS, true))
			{
				continue;
			}

			/*
				Only an 8bit value is touched, and the addresses arrive here already spelled in
				encoded words: this call would otherwise base64 a whole address line, angle
				brackets included, and no server would read an address out of the result.
			*/
			$result .= $name . ': ' . self::encodeMimeString($value, $this->charset) . $this->eol;
		}

		// Content-Type and the transfer encoding of the topmost part belong to the Multipart
		return $result . rtrim($this->multipart->toStringHeaders());
	}

	private function textPart(string $contentType, string $body): Part
	{
		return (new Part())
			->setEol($this->eol)
			->addHeader('Content-Type', $contentType . '; charset=' . $this->charset)
			->addHeader('Content-Transfer-Encoding', self::PART_ENCODING)
			->setBody($body)
		;
	}

	/**
	 * The attachments of the message as parts of it, and the markup with its links to the
	 * inline ones restored.
	 *
	 * An inline image - a logo in a signature, a picture inside the text - is a part of its
	 * own, and the markup points at it by an identifier internal to the message. The save
	 * path took the image out into a file of ours and rewrote that pointer to
	 * "aid:<row of the attachment>", keeping the original identifier nowhere: there is no
	 * column for it. So a new one is minted here, written into the markup and handed to the
	 * part as well - the way the send path does it for a message a human writes. Without it
	 * the images would travel as plain attached files: broken pictures in the text and a
	 * list of logo.png, image001.png below, for almost every message with a corporate
	 * signature.
	 *
	 * @return array{0: array, 1: string, 2: string[]} The parts, the markup, the names left out.
	 */
	private static function attachmentParts(array $rows, string $messageId, string $html): array
	{
		$parts = [];
		$missing = [];

		foreach ($rows as $row)
		{
			$name = Emoji::decode((string)($row['FILE_NAME'] ?? ''));
			$file = \CFile::makeFileArray((int)($row['FILE_ID'] ?? 0));

			if (!is_array($file) || empty($file['tmp_name']))
			{
				$missing[] = $name;

				continue;
			}

			$attachmentId = (int)($row['ID'] ?? 0);
			// The boundary the send path forgets: aid:1 matches inside aid:12 without it
			$pointer = sprintf('/aid:%u(?!\d)/i', $attachmentId);
			$contentId = self::contentId($messageId, $attachmentId);
			$isInline = (bool)preg_match($pointer, $html);

			if ($isInline)
			{
				$html = (string)preg_replace($pointer, 'cid:' . $contentId, $html);
			}

			$parts[] = [
				'ID' => $contentId,
				'NAME' => $name,
				'PATH' => $file['tmp_name'],
				'CONTENT_TYPE' => (string)($row['CONTENT_TYPE'] ?? '')
					?: (string)($file['type'] ?? '')
					?: 'application/octet-stream'
				,
				'RELATED' => $isInline,
			];
		}

		return [$parts, $html, $missing];
	}

	/**
	 * The identifier the markup and the part of an inline image meet by. Derived from the
	 * message and the row of the attachment, so a repeated run assembles the very same
	 * message. The original identifier is not restored - this one is ours; the matching
	 * does not look at it, as it lives neither in the fingerprint, nor in the bodies, nor
	 * in the names of the attachments.
	 */
	private static function contentId(string $messageId, int $attachmentId): string
	{
		return sprintf(
			'bxacid.%s@%s.mail',
			hash('crc32b', $messageId . '.' . $attachmentId),
			hash('crc32b', self::hostname()),
		);
	}

	private static function hostname(): string
	{
		if (defined('BX24_HOST_NAME') && BX24_HOST_NAME !== '')
		{
			return (string)BX24_HOST_NAME;
		}

		if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '')
		{
			return (string)SITE_SERVER_NAME;
		}

		return (string)Option::get('main', 'server_name', 'localhost');
	}

	/**
	 * The date of the message as its sender wrote it, taken from the stored header block -
	 * the very line the normalizer of the matching reads.
	 *
	 * The column of the date is the tempting mistake and is never used: it is written with
	 * the timezone offset of the portal added, so it depends on the settings and on the
	 * user, and messages would move by hours, in some mailboxes by a day. Without any date
	 * at all the kernel would stamp the current time and a human would meet a pile of mail
	 * "from today" - correspondence years old, invoices, contracts, all on one day, with
	 * the order of the list, the threads and the search by date broken and nothing to fix
	 * it afterwards but a re-upload.
	 *
	 * A block cut short of its date line falls back to the stamp of the old server: the
	 * difference between "sent" and "received" is usually seconds. With neither at hand the
	 * header is left out rather than filled with today.
	 */
	private static function dateHeader(\CMailHeader $header, ?DateTime $receivedAt): string
	{
		$date = trim((string)$header->getHeader('DATE'));

		if ($date !== '')
		{
			return $date;
		}

		return $receivedAt === null ? '' : date('r', $receivedAt->getTimestamp());
	}

	/**
	 * A field of the envelope as the message really had it.
	 *
	 * The column keeps the beginning of the header line and no more, so a field filled to
	 * the very edge of its column is taken from the stored header block instead - the way
	 * the send path does it. A column left empty is read out of the block for the same
	 * reason: the column is a copy of that line, and only the copy can have lost it.
	 *
	 * The names inside are spelled in encoded words right here: the serialization of the
	 * kernel encodes an 8bit header value whole, angle brackets included, which is right
	 * for a subject and breaks every address.
	 */
	private static function addressHeader(
		array $message,
		string $column,
		string $headerName,
		\CMailHeader $header,
		string $charset,
	): string
	{
		$value = Emoji::decode((string)($message[$column] ?? ''));

		if ($value === '' || mb_strlen($value) >= self::COLUMN_LIMIT)
		{
			$stored = Emoji::decode((string)$header->getHeader($headerName));

			if ($stored !== '')
			{
				$value = $stored;
			}
		}

		$encoded = [];

		foreach (explode(',', $value) as $item)
		{
			$address = new Address($item);

			if (!$address->validate())
			{
				continue;
			}

			$name = (string)$address->getName();

			if ($name === '')
			{
				$encoded[] = $address->getEmail();
			}
			elseif (self::is8Bit($name))
			{
				$encoded[] = sprintf('%s <%s>', self::encodeSubject($name, $charset), $address->getEmail());
			}
			else
			{
				$encoded[] = $address->get();
			}
		}

		return implode(', ', $encoded);
	}

	/**
	 * The stored header block, ready to be read out of.
	 *
	 * The Content-Type line is replaced first. The block was converted to the charset of the
	 * portal when it was saved, but that line still names the charset of the original
	 * message, and the parser reads the charset of the block out of it - so left in place it
	 * makes the parser convert the block a second time and turns every non-latin name into
	 * mojibake. Replaced and not merely dropped, because the parser reads that line without
	 * checking whether it is there.
	 */
	private static function parseStoredHeader(string $block, string $charset): \CMailHeader
	{
		$block = (string)preg_replace('/^Content-Type:[^\r\n]*\r?\n?/im', '', $block);
		$block .= sprintf("Content-Type: text/plain; charset=%s\r\n", $charset);

		return \CMailMessage::parseHeader($block, $charset);
	}

	private static function bracketed(string $identifier): string
	{
		$identifier = trim($identifier, " <>");

		return $identifier === '' ? '' : sprintf('<%s>', $identifier);
	}

	/**
	 * The bytes in our columns are the bytes of this portal, so the charset declared has to
	 * be its own. Only the transfer encoding of the parts is fixed by us, above.
	 */
	private static function portalCharset(): string
	{
		return defined('LANG_CHARSET') && LANG_CHARSET !== '' ? (string)LANG_CHARSET : 'utf-8';
	}
}
