<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation\Dto;

/**
 * Context of one page of the mail grid: which mailbox, which folder or virtual folder, the filter and
 * the sorting of the list, the size of the page and the cursor of the previous one.
 *
 * The current user is deliberately not a part of it: it is read from the server session by the adapter
 * that builds the request, never accepted from the client.
 */
final class ConversationListRequest
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 100;

	public function __construct(
		public readonly int $mailboxId,
		public readonly ?string $folder,
		public readonly ?string $virtualFolder,
		public readonly array $filter,
		public readonly array $sort,
		public readonly int $limit = self::DEFAULT_LIMIT,
		public readonly ?string $cursor = null,
	)
	{
	}
}
