<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\User;

/**
 * Deterministic per-user identity color — the single source shared by the live-collaboration
 * caret/awareness color (CollaborationProvider) and every photoless avatar background (history
 * authors, viewers). A user's cursor and their avatar therefore read the same color everywhere,
 * including the historical timeline where no live awareness color exists. The JS side honors this
 * value verbatim (participant.color / viewer.color) and never recomputes it, so the formula lives
 * here only.
 */
final class IdentityColor
{
	public static function forUser(int $userId): string
	{
		return '#' . substr(md5((string)$userId), 0, 6);
	}
}
