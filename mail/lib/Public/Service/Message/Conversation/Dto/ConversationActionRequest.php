<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation\Dto;

/**
 * Context of a group action over one row of the conversation grid: which mailbox, which conversation,
 * the folder the client is looking at, the action itself and the composition of the row it drew.
 *
 * There is deliberately no list of messages here. The elements a group action changes are resolved by
 * the service from the conversation, the mailbox and the folder, so a client cannot widen what is
 * touched by naming identifiers of its own. `expectedVersion` is the composition of the row the client
 * drew and is compared with the current one; it decides whether the action runs at all, never which
 * messages it runs on.
 *
 * The current user is not a part of the request either: it is read from the server session by the
 * adapter that builds it.
 */
final class ConversationActionRequest
{
	/**
	 * @param array $actionParameters payload of the action itself, `folderPath` for a move
	 * @param string $expectedVersion composition of the row the client drew
	 */
	public function __construct(
		public readonly int $mailboxId,
		public readonly string $conversationId,
		public readonly string $folderPath,
		public readonly string $action,
		public readonly array $actionParameters = [],
		public readonly string $expectedVersion = '',
	)
	{
	}

	/** Destination of a move, exactly as the client named it; the service decides whether it exists. */
	public function targetFolderPath(): string
	{
		$path = $this->actionParameters['folderPath'] ?? '';

		return is_scalar($path) ? (string)$path : '';
	}
}
