<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Compose;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Message;

/**
 * The source message carried over into the compose form: its quote and the files that come with it.
 *
 * Both are built in one pass on purpose. Inline images are marked with __bxacid in the very body the
 * quote is made of, and a reply keeps only the attachments that stayed in that body, so splitting
 * the two apart would mean reading the sanitized body cache twice.
 *
 * The quote is wrapped by the same public call as before, so the phrases and the blockquote markup
 * stay in one place. The message is read only: neither BODY_HTML nor the cache of the sanitized body
 * is written to.
 *
 * Appending the folded quote to the body when the letter is sent is the job of the client: the form
 * gets the quote as a field of its own.
 */
class MessageQuoteBuilder
{
	/** Inline attachments are marked in the body by the uploader of the old form. */
	private const INLINE_FILE_MARKER_REGEX = '#(\?|&)__bxacid=(n?\d+)#i';

	/** Only Disk objects reach the form: Helper\Message::prepare() prefixes their id with "n". */
	private const DISK_FILE_ID_REGEX = '/^n\d+$/i';

	/**
	 * @param array $message Message prepared by Helper\Message::prepare().
	 * @param int|null $parentMessageId Source message of the form, null for a new letter.
	 * @param string $scenario One of the ComposeFormDataProvider::SCENARIO_* values.
	 * @return array{quote: string, files: array} Quote HTML and the attachments section of DTO-01.
	 */
	public function build(array $message, ?int $parentMessageId, string $scenario): array
	{
		if ($parentMessageId === null)
		{
			return [
				'quote' => '',
				'files' => [],
			];
		}

		[$body, $sanitized] = $this->resolveBody($message, $parentMessageId);

		return [
			'quote' => $this->wrapWithQuote($message, $body, $sanitized),
			'files' => $this->selectFiles($message, $scenario, $this->findInlineFileIds($body)),
		];
	}

	/**
	 * Body of the source message and whether it is sanitized already.
	 *
	 * An HTML body comes from the cache of the message view screen; on a miss the raw one is taken
	 * and sanitized synchronously while the quote is wrapped. A message without HTML is quoted by its
	 * plain text, which is escaped here and needs no sanitizing afterwards.
	 *
	 * @return array{0: string, 1: bool}
	 */
	private function resolveBody(array $message, int $parentMessageId): array
	{
		if (trim((string)($message['BODY_HTML'] ?? '')))
		{
			$cachedBody = $this->loadSanitizedBody($parentMessageId);

			return $cachedBody
				? [$cachedBody, true]
				: [(string)$message['BODY_HTML'], false]
			;
		}

		$plainText = htmlspecialcharsbx((string)($message['BODY'] ?? ''));

		return [(string)preg_replace('/(\s*(\r\n|\n|\r))+/', '<br>', $plainText), true];
	}

	/**
	 * The header of the quote carries the subject of the source message without the Re/Fwd prefix and
	 * the date the mail server stamped, falling back to the one the sender put in the message.
	 *
	 * Headers of the source message are escaped here and not inside the shared wrapper: the quote of
	 * this form is handed to the client and put into the body of the editor, while the other callers
	 * of the wrapper are the screens of the old form. Only the body is sanitized by the wrapper, and
	 * the date it prints is built from a timestamp.
	 */
	private function wrapWithQuote(array $message, string $body, bool $sanitized): string
	{
		return Message::wrapTheMessageWithAQuote(
			$body,
			htmlspecialcharsbx((string)($message['ORIGINAL_SUBJECT'] ?? $message['SUBJECT'] ?? '')),
			$message['INTERNALDATE'] ?? $message['FIELD_DATE'] ?? '',
			$this->escapeContacts((array)($message['__from'] ?? [])),
			$this->escapeContacts((array)($message['__to'] ?? [])),
			$this->escapeContacts((array)($message['__cc'] ?? [])),
			$sanitized,
		);
	}

	/**
	 * Name and address are the fields the header of the quote prints; the rest of the contact is left
	 * as it came. Escaping keeps the single quotes the wrapper strips off a display name.
	 */
	private function escapeContacts(array $contacts): array
	{
		return array_map(
			static fn(array $contact): array => array_merge($contact, [
				'name' => htmlspecialcharsbx((string)($contact['name'] ?? '')),
				'email' => htmlspecialcharsbx((string)($contact['email'] ?? '')),
			]),
			$contacts,
		);
	}

	/**
	 * @return string[] Ids of the attachments the body of the quote shows inline.
	 */
	private function findInlineFileIds(string $body): array
	{
		preg_match_all(self::INLINE_FILE_MARKER_REGEX, $body, $matches);

		return $matches[2];
	}

	/**
	 * Attachments of the source message the form starts with.
	 *
	 * A forward carries all of them, a reply only the ones that stayed inline in the quote: the rest
	 * would be attached twice. Legacy b_file attachments that have no Disk object are dropped
	 * silently: the form works with Disk objects alone.
	 *
	 * @param string[] $inlineFileIds
	 */
	private function selectFiles(array $message, string $scenario, array $inlineFileIds): array
	{
		$keepInlineOnly = ComposeFormDataProvider::isReplyScenario($scenario);
		$files = [];

		foreach ((array)($message['__files'] ?? []) as $file)
		{
			$id = (string)($file['id'] ?? '');

			if (!preg_match(self::DISK_FILE_ID_REGEX, $id))
			{
				continue;
			}

			if ($keepInlineOnly && !in_array($id, $inlineFileIds, true))
			{
				continue;
			}

			// No address of the file is handed over: the cards of the form are drawn from the control of the
			// Disk uploader, and the template takes the id alone.
			$files[] = [
				'id' => $id,
				'name' => (string)($file['name'] ?? ''),
				'size' => (string)($file['size'] ?? ''),
				'bytes' => (int)($file['bytes'] ?? 0),
			];
		}

		return $files;
	}

	protected function loadSanitizedBody(int $messageId): ?string
	{
		return (new Helper\Cache\SanitizedBodyCache())->get($messageId);
	}
}
