<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation\Dto;

/**
 * Context of one portion of an expanded conversation: which mailbox, which conversation, the filter
 * the grid is showing, the size of the portion and the cursor of the previous one.
 *
 * The current user is deliberately not a part of it: it is read from the server session by the adapter
 * that builds the request, never accepted from the client. The conversation is named by the very
 * identifier the row of the grid carries, and access to it is decided by the service.
 */
final class ConversationItemsRequest
{
	/** Elements of the very first portion, opened right inside the grid. */
	public const FIRST_PAGE_SIZE = 3;
	public const DEFAULT_LIMIT = 10;
	public const MAX_LIMIT = 10;

	public function __construct(
		public readonly int $mailboxId,
		public readonly string $conversationId,
		public readonly array $filter = [],
		public readonly int $limit = self::DEFAULT_LIMIT,
		public readonly ?string $cursor = null,
	)
	{
	}

	public function boundedLimit(): int
	{
		return max(1, min($this->limit, self::MAX_LIMIT));
	}
}
