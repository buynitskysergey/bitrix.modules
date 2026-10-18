<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation\Dto;

/**
 * One row of the grid: a single message, a single draft, or a conversation of several available
 * elements. A component of one available element keeps the row model of the plain list, so that a
 * client that already draws messages and drafts draws it without knowing about conversations.
 */
final class ConversationSummary
{
	public const TYPE_MESSAGE = 'message';
	public const TYPE_DRAFT = 'draft';
	public const TYPE_CONVERSATION = 'conversation';

	/** Identifier the client hands back to the expansion of the row. */
	public const CONVERSATION_ID_PREFIX = 'c:';

	public const ACTION_MARK_READ = 'markRead';
	public const ACTION_MARK_UNREAD = 'markUnread';
	public const ACTION_MOVE = 'move';
	public const ACTION_DELETE = 'delete';
	public const ACTION_MARK_SPAM = 'markSpam';
	public const ACTION_DELETE_DRAFT = 'deleteDraft';

	/** Group mutations of a single physical folder. */
	public const FOLDER_ACTIONS = [
		self::ACTION_MARK_READ,
		self::ACTION_MARK_UNREAD,
		self::ACTION_MOVE,
		self::ACTION_DELETE,
		self::ACTION_MARK_SPAM,
	];

	/** Group mutations of a folder that holds outgoing mail. */
	public const OUTCOME_FOLDER_ACTIONS = [
		self::ACTION_MOVE,
		self::ACTION_DELETE,
	];

	/** Group mutations of the drafts view. */
	public const DRAFT_ACTIONS = [self::ACTION_DELETE_DRAFT];

	/**
	 * Server identifier of the conversation a row of the grid carries, or null when the client named
	 * something that is not one. The prefix lives here, and so does reading it back.
	 */
	public static function readConversationId(string $conversationId): ?int
	{
		$value = str_starts_with($conversationId, self::CONVERSATION_ID_PREFIX)
			? substr($conversationId, strlen(self::CONVERSATION_ID_PREFIX))
			: $conversationId;

		return preg_match('/^[1-9]\d*$/', $value) === 1 ? (int)$value : null;
	}

	/**
	 * @param array|null $message row model of the plain list, for a single available message
	 * @param array|null $draft row model of the plain drafts list, for a standalone draft
	 * @param array<int, array{name: string, email: string}> $participants
	 * @param string[] $availableActions
	 */
	private function __construct(
		public readonly string $type,
		public readonly int|string $id,
		public readonly ?array $message,
		public readonly ?array $draft,
		public readonly int $representativeMessageId,
		public readonly string $subject,
		public readonly string $preview,
		public readonly array $participants,
		public readonly string $lastActivityAt,
		public readonly int $messageCount,
		public readonly int $unreadCount,
		public readonly bool $hasDraft,
		public readonly int $matchedCount,
		public readonly array $availableActions,
		public readonly bool $incomplete,
		public readonly bool $retryAvailable,
		public readonly string $version,
	)
	{
	}

	/**
	 * @param array $message row model of the plain list
	 * @param array<int, array{name: string, email: string}> $participants
	 * @param string[] $availableActions
	 */
	public static function forMessage(
		int $messageId,
		array $message,
		string $subject,
		string $preview,
		array $participants,
		string $lastActivityAt,
		int $unreadCount,
		int $matchedCount,
		array $availableActions,
		string $version,
	): self
	{
		return new self(
			type: self::TYPE_MESSAGE,
			id: $messageId,
			message: $message,
			draft: null,
			representativeMessageId: $messageId,
			subject: $subject,
			preview: $preview,
			participants: $participants,
			lastActivityAt: $lastActivityAt,
			messageCount: 1,
			unreadCount: $unreadCount,
			hasDraft: false,
			matchedCount: $matchedCount,
			availableActions: $availableActions,
			incomplete: false,
			retryAvailable: false,
			version: $version,
		);
	}

	/**
	 * @param array $draft row model of the plain drafts list
	 * @param array<int, array{name: string, email: string}> $participants
	 * @param string[] $availableActions
	 */
	public static function forDraft(
		int $draftId,
		array $draft,
		string $subject,
		string $preview,
		array $participants,
		string $lastActivityAt,
		array $availableActions,
		string $version,
	): self
	{
		return new self(
			type: self::TYPE_DRAFT,
			id: 'd:' . $draftId,
			message: null,
			draft: $draft,
			representativeMessageId: 0,
			subject: $subject,
			preview: $preview,
			participants: $participants,
			lastActivityAt: $lastActivityAt,
			messageCount: 1,
			unreadCount: 0,
			hasDraft: true,
			matchedCount: 1,
			availableActions: $availableActions,
			incomplete: false,
			retryAvailable: false,
			version: $version,
		);
	}

	/**
	 * @param array<int, array{name: string, email: string}> $participants
	 * @param string[] $availableActions
	 */
	public static function forConversation(
		int $conversationId,
		int $representativeMessageId,
		string $subject,
		string $preview,
		array $participants,
		string $lastActivityAt,
		int $messageCount,
		int $unreadCount,
		bool $hasDraft,
		int $matchedCount,
		array $availableActions,
		bool $incomplete,
		string $version,
	): self
	{
		return new self(
			type: self::TYPE_CONVERSATION,
			id: self::CONVERSATION_ID_PREFIX . $conversationId,
			message: null,
			draft: null,
			representativeMessageId: $representativeMessageId,
			subject: $subject,
			preview: $preview,
			participants: $participants,
			lastActivityAt: $lastActivityAt,
			messageCount: $messageCount,
			unreadCount: $unreadCount,
			hasDraft: $hasDraft,
			matchedCount: $matchedCount,
			availableActions: $availableActions,
			incomplete: $incomplete,
			retryAvailable: $incomplete,
			version: $version,
		);
	}

	public function toArray(): array
	{
		return [
			'type' => $this->type,
			'id' => $this->id,
			'message' => $this->message,
			'draft' => $this->draft,
			'representativeMessageId' => $this->representativeMessageId,
			'subject' => $this->subject,
			'preview' => $this->preview,
			'participants' => $this->participants,
			'lastActivityAt' => $this->lastActivityAt,
			'messageCount' => $this->messageCount,
			'unreadCount' => $this->unreadCount,
			'hasDraft' => $this->hasDraft,
			'matchedCount' => $this->matchedCount,
			'availableActions' => $this->availableActions,
			'incomplete' => $this->incomplete,
			'retryAvailable' => $this->retryAvailable,
			'version' => $this->version,
		];
	}
}
