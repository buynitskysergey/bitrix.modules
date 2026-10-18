<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation\Dto;

/**
 * One portion of an expanded conversation.
 *
 * `remainingCount` is the exact number of elements that are available but not shown yet, so the
 * client labels its own `load more` without guessing. `version` is the composition of the very row
 * the grid drew: a value different from the one the client holds means the row itself is outdated.
 */
final class ConversationItemsPage
{
	/** A repeat of the very same request is what can complete an incomplete portion. */
	public readonly bool $retryAvailable;

	/**
	 * @param ConversationItem[] $items
	 */
	public function __construct(
		public readonly int $conversationId,
		public readonly array $items,
		public readonly ?string $nextCursor,
		public readonly int $remainingCount,
		public readonly bool $incomplete,
		public readonly string $version,
	)
	{
		$this->retryAvailable = $incomplete;
	}

	public static function empty(int $conversationId, string $version): self
	{
		return new self($conversationId, [], null, 0, false, $version);
	}

	public function toArray(): array
	{
		return [
			'conversationId' => ConversationSummary::CONVERSATION_ID_PREFIX . $this->conversationId,
			'items' => array_map(
				static fn(ConversationItem $item): array => $item->toArray(),
				$this->items,
			),
			'nextCursor' => $this->nextCursor,
			'remainingCount' => $this->remainingCount,
			'incomplete' => $this->incomplete,
			'retryAvailable' => $this->retryAvailable,
			'version' => $this->version,
		];
	}
}
