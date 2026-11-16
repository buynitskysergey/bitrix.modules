<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess\Mention;

use Bitrix\Note\Internal\Service\Mention\ResolvedMention;

/**
 * The single reason the public circuit ever gives for an unavailable mention.
 *
 * The value is the one the ordinary answer collapses to - `ResolvedMention::publicReason()` folds
 * `deleted` and `no_access` into `unavailable` so the reason cannot work as an existence oracle.
 * Here the reason is not folded afterwards but built this way from the start: `publicReason()` is
 * private, and the only public way to it, `toArray()`, carries `avatar` and `isCurrentUser` - keys
 * the public answer has to be free of by composition, not by cleaning.
 */
final class PublicMentionReason
{
	public const UNAVAILABLE = 'unavailable';

	public static function unavailable(string $type, int $id): ResolvedMention
	{
		return ResolvedMention::unavailable($type, $id, self::UNAVAILABLE);
	}
}
