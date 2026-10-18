<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation;

/**
 * Composition of a conversation row: it changes whenever the client has to reload what it already
 * drew.
 *
 * The row of the grid and the expansion of that row answer with the very same string, so a client
 * that compares them tells an outdated composition from a current one. That only holds while both
 * build the value here and out of the same facts.
 */
final class ConversationVersion
{
	private const LENGTH = 32;
	private const HASH_ALGORITHM = 'sha256';

	public static function of(
		int $conversationId,
		int $messageCount,
		int $representativeMessageId,
		int $draftCount,
		int $revision = 0,
		string $draftMembershipFingerprint = '',
	): string
	{
		return substr(
			hash(
				self::HASH_ALGORITHM,
				sprintf(
					'%d:%d:%d:%d:%d:%s',
					$conversationId,
					$messageCount,
					$representativeMessageId,
					$draftCount,
					$revision,
					$draftMembershipFingerprint,
				),
			),
			0,
			self::LENGTH,
		);
	}
}
