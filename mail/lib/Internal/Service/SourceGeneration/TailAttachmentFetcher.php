<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Internal\Service\Attachment\FileNameNormalizer;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Main\Config\Option;

/**
 * The attachments of a letter of the tail, downloaded from the source still serving the
 * mailbox and kept in our own storage.
 *
 * Two reasons the stage does this before it assembles anything. The attachments are the
 * body of the letter for the people who will read it after the transfer, and the old
 * source is the only place they exist: once the mailbox has been handed over, a download
 * through a placement of the retained generation is refused by the boundary and the files
 * are gone for good. And they are worth having whether the append succeeds or not - a
 * file in our storage is a file the deal in CRM can open.
 *
 * The lazy path of the reading screens ({@see Helper\Message::ensureAttachments()}) does
 * the very same download, and builds an engine of its own for every single letter: a
 * connection, a folder listing and a login per letter. A tail of thousands would not fit
 * into the window of the stage that way. Everything that path does AFTER the download -
 * the attachment rows, the links from the markup to the inline images, the one update of
 * the markup - is repeated here, over the one engine of the pass.
 */
class TailAttachmentFetcher
{
	/**
	 * Gives the letter the attachments it never received.
	 *
	 * @param Helper\Mailbox|null $engine The engine of the generation serving the mailbox,
	 *        built once for the whole pass; null when that source cannot be reached at all.
	 * @param array $message A row of b_mail_message with the coordinates of the archived
	 *        placement joined in: ID, MAILBOX_ID, ATTACHMENTS, OPTIONS, BODY_HTML, DIR_MD5,
	 *        MSG_UID, GENERATION_ID. Left with the markup and the count the download changed.
	 * @return bool False when the letter is still without the files it was expecting - the
	 *         source did not hand them over, or our own storage refused to keep them. Such a
	 *         letter is not appended at all: after the switch the archived placement serves no
	 *         download anymore, so a copy that went up without its files is a file lost for
	 *         good, and a stub of the kernel in their place is worse than either.
	 */
	public function fetch(?Helper\Mailbox $engine, array &$message): bool
	{
		if (!$this->isPending($message))
		{
			return true;
		}

		$attachments = $engine?->downloadAttachments($message);

		if (!is_array($attachments) || $attachments === [])
		{
			$this->report($message, 'the source did not hand them over');

			return false;
		}

		$expected = $this->expectedCount($message);

		if (count($attachments) !== $expected)
		{
			$this->report($message, 'the source returned an incomplete set');

			return false;
		}

		$stored = $this->storedFingerprints((int)$message['ID']);

		if ($stored === null)
		{
			$this->report($message, 'an already stored file could not be identified');

			return false;
		}

		$source = [];
		$attachmentIndex = 0;
		foreach ($attachments as $part)
		{
			++$attachmentIndex;
			$hasSourceFileName = trim((string)($part['FILENAME'] ?? '')) !== '';
			$part['FILENAME'] = FileNameNormalizer::normalize(
				(string)($part['FILENAME'] ?? ''),
				(int)$message['MAILBOX_ID'],
				(int)$message['ID'],
				$attachmentIndex,
				(string)($part['CONTENT-TYPE'] ?? ''),
			);

			$source[] = [
				'part' => $part,
				'hasSourceFileName' => $hasSourceFileName,
			];
		}

		$missing = [];
		foreach ([true, false] as $hasSourceFileName)
		{
			foreach ($source as $sourceIndex => $item)
			{
				if ($item['hasSourceFileName'] !== $hasSourceFileName)
				{
					continue;
				}

				$part = $item['part'];
				$fingerprint = $this->sourceFingerprint($part, $hasSourceFileName);
				$storedIndex = $this->findStoredIndex(
					$stored,
					$hasSourceFileName ? 'named' : 'content',
					$fingerprint,
				);

				if ($storedIndex === false)
				{
					$missing[$sourceIndex] = $part;
				}
				else
				{
					unset($stored[$storedIndex]);
				}
			}
		}
		ksort($missing);

		if ($this->store($message, $missing) < count($missing))
		{
			/*
				The bytes were on the wire and our own storage refused them - a full disk, a
				quota, a rejected file. Ignoring the answer of the save was the sharper half of
				the defect: the letter would go up without a file that nobody can download
				afterwards, and the counters would call the pass a clean one.
			*/
			$this->report($message, 'our own storage did not keep them');

			return false;
		}

		return true;
	}

	/**
	 * Whether the letter is one of those whose attachments were left on the server: it
	 * carries none of its own and its own record says it should have some.
	 *
	 * Asked by the caller as well, before it opens a connection to that server: a pass over
	 * letters that have their files already needs no such connection at all.
	 */
	public function isPending(array $message): bool
	{
		$expected = $this->expectedCount($message);

		return (int)($message['ATTACHMENTS'] ?? 0) < $expected
			&& $expected > 0
			&& Option::get('mail', 'save_attachments', B_MAIL_SAVE_ATTACHMENTS) === 'Y'
		;
	}

	private function report(array $message, string $reason): void
	{
		AddMessage2Log(
			sprintf(
				'The attachments of the message %u of the mailbox %u are not there for the tail append: %s',
				(int)($message['ID'] ?? 0),
				(int)($message['MAILBOX_ID'] ?? 0),
				$reason,
			),
			'mail',
			2,
			false,
		);
	}

	/**
	 * @param array $parts Parts of the letter, as the save path receives them.
	 * @return int How many of them our own storage kept.
	 */
	private function store(array &$message, array $parts): int
	{
		$originalHtml = (string)($message['BODY_HTML'] ?? '');
		$html = $originalHtml;
		$created = 0;

		foreach ($parts as $part)
		{
			$attachmentId = (int)$this->addAttachment([
				'MESSAGE_ID' => (int)$message['ID'],
				'FILE_NAME' => (string)($part['FILENAME'] ?? ''),
				'CONTENT_TYPE' => (string)($part['CONTENT-TYPE'] ?? ''),
				'FILE_DATA' => $part['BODY'] ?? '',
				'CONTENT_ID' => (string)($part['CONTENT-ID'] ?? ''),
			]);

			if ($attachmentId <= 0)
			{
				continue;
			}

			++$created;

			if ($html !== '')
			{
				$html = Helper\Message::replaceBodyInlineImgContentId(
					$html,
					(string)($part['CONTENT-ID'] ?? ''),
					$attachmentId,
				);
			}
		}

		if ($created <= 0)
		{
			return 0;
		}

		$message['ATTACHMENTS'] = (int)($message['ATTACHMENTS'] ?? 0) + $created;

		// One update for the whole letter, whatever the number of the images its markup points at
		if ($html !== $originalHtml)
		{
			$message['BODY_HTML'] = $html;

			\CMailMessage::update((int)$message['ID'], ['BODY_HTML' => $html], (int)$message['MAILBOX_ID']);
		}

		return $created;
	}

	private function expectedCount(array $message): int
	{
		$options = $message['OPTIONS'] ?? [];

		return is_array($options) ? (int)($options['attachments'] ?? 0) : 0;
	}

	/**
	 * @return array<int, array{named: string, content: string}>|null One identity per stored row,
	 *         including equal files separately.
	 */
	private function storedFingerprints(int $messageId): ?array
	{
		$rows = MailMessageAttachmentTable::getList([
			'select' => ['FILE_ID', 'FILE_NAME', 'CONTENT_TYPE'],
			'filter' => ['=MESSAGE_ID' => $messageId],
			'order' => ['ID' => 'ASC'],
		])->fetchAll();

		$fingerprints = [];
		foreach ($rows as $row)
		{
			$file = \CFile::makeFileArray((int)$row['FILE_ID']);
			$path = is_array($file) ? (string)($file['tmp_name'] ?? '') : '';
			$body = $path !== '' ? file_get_contents($path) : false;

			if ($body === false)
			{
				return null;
			}

			$fingerprints[] = [
				'named' => $this->fingerprint((string)$row['FILE_NAME'], (string)$row['CONTENT_TYPE'], $body),
				'content' => $this->fingerprint('', (string)$row['CONTENT_TYPE'], $body),
			];
		}

		return $fingerprints;
	}

	private function sourceFingerprint(array $part, bool $includeFileName): string
	{
		return $this->fingerprint(
			$includeFileName ? (string)($part['FILENAME'] ?? '') : '',
			(string)($part['CONTENT-TYPE'] ?? ''),
			(string)($part['BODY'] ?? ''),
		);
	}

	/**
	 * @param array<int, array{named: string, content: string}> $stored
	 */
	private function findStoredIndex(array $stored, string $identityType, string $fingerprint): int|false
	{
		foreach ($stored as $index => $identity)
		{
			if ($identity[$identityType] === $fingerprint)
			{
				return $index;
			}
		}

		return false;
	}

	private function fingerprint(string $fileName, string $contentType, string $body): string
	{
		return hash('sha256', serialize([
			$fileName,
			mb_strtolower($contentType),
			strlen($body),
			hash('sha256', $body),
		]));
	}

	/**
	 * The one write of a downloaded file, the way the lazy path of the reading screens writes
	 * it. Its own seam so that a test can answer with the refusal of a full storage, which no
	 * fixture of a mail server can produce.
	 *
	 * @return int|false The row of the attachment, false when nothing was kept.
	 */
	protected function addAttachment(array $fields)
	{
		return \CMailMessage::addAttachment($fields);
	}
}
