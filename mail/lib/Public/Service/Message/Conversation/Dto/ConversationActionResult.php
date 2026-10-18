<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation\Dto;

/**
 * Exact outcome of a group action (DTO-03).
 *
 * The answer names what was really changed and what was refused, element by element: a partial failure
 * is never rounded up to a common success, and an element the action did not reach is neither in the
 * processed lists nor silently forgotten. `version` is the composition of the row after the action, and
 * `reloadRequired` tells the client whether its own copy of the row still describes what the server
 * holds.
 */
final class ConversationActionResult
{
	/** The existing operation refused the element, or did not report it as processed. */
	public const FAILURE_OPERATION_FAILED = 'OPERATION_FAILED';

	/** Access to the mailbox was gone by the time the element was about to change. */
	public const FAILURE_ACCESS_LOST = 'ACCESS_LOST';

	public const ITEM_MESSAGE_PREFIX = 'm:';
	public const ITEM_DRAFT_PREFIX = 'd:';

	/**
	 * @param int[] $processedMessageIds
	 * @param int[] $processedDraftIds
	 * @param list<array{itemId: string, code: string}> $failed
	 */
	public function __construct(
		public readonly int $conversationId,
		public readonly array $processedMessageIds,
		public readonly array $processedDraftIds,
		public readonly array $failed,
		public readonly string $version,
		public readonly bool $reloadRequired,
	)
	{
	}

	/** @return array{itemId: string, code: string} */
	public static function messageFailure(int $messageId, string $code): array
	{
		return ['itemId' => self::ITEM_MESSAGE_PREFIX . $messageId, 'code' => $code];
	}

	/** @return array{itemId: string, code: string} */
	public static function draftFailure(int $draftId, string $code): array
	{
		return ['itemId' => self::ITEM_DRAFT_PREFIX . $draftId, 'code' => $code];
	}

	public function toArray(): array
	{
		return [
			'conversationId' => ConversationSummary::CONVERSATION_ID_PREFIX . $this->conversationId,
			'processedMessageIds' => $this->processedMessageIds,
			'processedDraftIds' => $this->processedDraftIds,
			'failed' => $this->failed,
			'version' => $this->version,
			'reloadRequired' => $this->reloadRequired,
		];
	}
}
