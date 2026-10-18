<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation\Dto;

/**
 * One element of an expanded conversation: an existing message, or an internal draft answering one.
 *
 * The numeric identity of a message is left untouched; the client keeps addressing it by
 * `messageId` and keeps drawing it with the row model of the plain list. The string `id` only tells
 * the two kinds of element apart inside one expansion.
 */
final class ConversationItem
{
	public const KIND_MESSAGE = 'message';
	public const KIND_DRAFT = 'draft';

	public const FOLDER_TYPE_MAILBOX = 'folder';
	public const FOLDER_TYPE_DRAFT = 'draft';

	private const MESSAGE_ID_PREFIX = 'm:';
	private const DRAFT_ID_PREFIX = 'd:';

	/**
	 * @param array{type: string, path: string|null, name: string|null} $folder
	 * @param array|null $message row model of the plain list
	 * @param array|null $draft row model of the plain drafts list
	 */
	private function __construct(
		public readonly string $kind,
		public readonly string $id,
		public readonly ?int $messageId,
		public readonly ?int $draftId,
		public readonly bool $matched,
		public readonly array $folder,
		public readonly ?array $message,
		public readonly ?array $draft,
	)
	{
	}

	/** @param array $message row model of the plain list */
	public static function forMessage(
		int $messageId,
		array $message,
		string $folderPath,
		string $folderName,
		bool $matched,
	): self
	{
		return new self(
			kind: self::KIND_MESSAGE,
			id: self::MESSAGE_ID_PREFIX . $messageId,
			messageId: $messageId,
			draftId: null,
			matched: $matched,
			folder: [
				'type' => self::FOLDER_TYPE_MAILBOX,
				'path' => $folderPath,
				'name' => $folderName,
			],
			message: $message,
			draft: null,
		);
	}

	/** @param array $draft row model of the plain drafts list */
	public static function forDraft(int $draftId, array $draft, bool $matched): self
	{
		return new self(
			kind: self::KIND_DRAFT,
			id: self::DRAFT_ID_PREFIX . $draftId,
			messageId: null,
			draftId: $draftId,
			matched: $matched,
			// An internal draft lives in no folder of the server; the row is labelled by its kind.
			folder: [
				'type' => self::FOLDER_TYPE_DRAFT,
				'path' => null,
				'name' => null,
			],
			message: null,
			draft: $draft,
		);
	}

	public function toArray(): array
	{
		return [
			'kind' => $this->kind,
			'id' => $this->id,
			'messageId' => $this->messageId,
			'draftId' => $this->draftId,
			'matched' => $this->matched,
			'folder' => $this->folder,
			'message' => $this->message,
			'draft' => $this->draft,
		];
	}
}
