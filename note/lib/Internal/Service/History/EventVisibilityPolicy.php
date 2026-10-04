<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\History;

/**
 * [P2.T1 / MTX-01] Role-based visibility over b_note_event. Must be applied
 * server-side BEFORE the caller's category filter (see FeedProvider) — the
 * filter can only narrow what a role already sees, never widen it (a reader
 * must never get `moved`/`access_changed` even via an explicit request).
 *
 * Roles (derived from DocumentAccessService::getCurrentUserSnapshot()):
 *  - owner/admin: PortalAdmin OR collection LEVEL_MANAGE(30)+ -> canEditCollection
 *  - editor: not owner/admin AND effective LEVEL_EDIT -> canEdit
 *  - reader: everyone else with LEVEL_VIEW (gate is checked by the caller)
 */
final class EventVisibilityPolicy
{
	// Visible to every role that can read the feed at all (reader and up).
	private const TYPES_ALL_ROLES = [
		'created',
		'content_changed',
		'title_changed',
		'archived',
		'archive_restored',
		'trashed',
		'trash_restored',
	];

	// Additionally visible to editor and owner/admin.
	private const TYPES_EDITOR_UP = ['moved'];

	// Only visible to owner/admin.
	private const TYPES_OWNER_ONLY = ['access_changed'];

	/**
	 * @param array{canEdit?: bool, canEditCollection?: bool} $accessSnapshot
	 * @return string[] event types the role is allowed to see
	 */
	public static function allowedTypes(array $accessSnapshot): array
	{
		$isOwnerOrAdmin = (bool)($accessSnapshot['canEditCollection'] ?? false);
		$isEditor = !$isOwnerOrAdmin && (bool)($accessSnapshot['canEdit'] ?? false);

		if ($isOwnerOrAdmin)
		{
			return [...self::TYPES_ALL_ROLES, ...self::TYPES_EDITOR_UP, ...self::TYPES_OWNER_ONLY];
		}

		if ($isEditor)
		{
			return [...self::TYPES_ALL_ROLES, ...self::TYPES_EDITOR_UP];
		}

		return self::TYPES_ALL_ROLES;
	}

	/**
	 * [MTX-01] Whether the type is visible to every role that can read the feed (reader and up).
	 * The live-push channel (HistoryPullGateway) has no per-recipient role context, so it may
	 * broadcast only these types; role-restricted ones (moved/access_changed) must stay off the
	 * live channel and reach privileged roles solely via the ACL-filtered listFeed fetch.
	 */
	public static function isVisibleToAllRoles(string $type): bool
	{
		return in_array($type, self::TYPES_ALL_ROLES, true);
	}
}
