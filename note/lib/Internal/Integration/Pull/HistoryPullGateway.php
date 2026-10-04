<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Pull;

use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Entity\History\Event;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\History\EventVisibilityPolicy;
use Bitrix\Note\Public\Provider\FeedProvider;

/**
 * Best-effort live push for the history sidebar: broadcasts a newly recorded
 * b_note_event row to every client watching NOTE_DOC_HISTORY_{documentId} (the tag
 * is registered server-side in CollaborationProvider::subscribeUserToDocumentTag,
 * alongside NOTE_DOC_AWARE_{documentId} — FE extendWatch only renews, it cannot
 * subscribe a new tag on its own).
 *
 * Reuses FeedProvider::enrichOne() so the pushed `event` tile has exactly the same
 * actor/authors/versionAvailable shape as one listFeed page item — the sidebar can
 * splice it into the feed without a re-fetch.
 */
final class HistoryPullGateway
{
	public const TAG_PREFIX = 'NOTE_DOC_HISTORY_';
	private const COMMAND = 'documentHistoryEvent';

	public function __construct(
		private readonly FeedProvider $feedProvider = new FeedProvider(),
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly PushNotificationService $pushService = new PushNotificationService(),
	) {}

	public static function watchTag(int $documentId): string
	{
		return self::TAG_PREFIX . $documentId;
	}

	/**
	 * Called from EventLogService::record() right after the event row is inserted (the
	 * caller already has EVENT_ID). record() runs inside a caller-owned transaction in
	 * some call sites (Compact/RestoreFromRecycleBin) — a rollback there could turn this
	 * into a "phantom" push for an event that never really happened. Pull is a live-update
	 * signal, not a source of truth (the sidebar always falls back to listFeed), and a
	 * rollback right after a fresh write is the rare error path, so emitting here
	 * best-effort is an accepted trade-off over threading dispatchAfterCommit through
	 * every EventLogService::record() call site.
	 */
	public function emit(Event $event): void
	{
		if (!Configuration::isActivityEnabled())
		{
			return;
		}

		if ($event->getScope() !== EventTable::SCOPE_DOCUMENT)
		{
			return;
		}

		$documentId = $event->getEntityId();
		if ($documentId <= 0)
		{
			return;
		}

		// [MTX-01] The tag broadcast reaches every watcher (readers included) with no
		// per-recipient role context, so role-restricted types (moved/access_changed) must
		// not go out live — they would leak event metadata to readers. Privileged roles
		// still get them via the ACL-filtered listFeed fetch; only all-roles types splice in live.
		if (!EventVisibilityPolicy::isVisibleToAllRoles($event->getEventType()))
		{
			return;
		}

		try
		{
			$document = $this->documentRepository->getMetaById($documentId, ['ID', 'COLLECTION_ID']);
			if ($document === null)
			{
				return;
			}

			$tile = $this->feedProvider->enrichOne($event);
			$tile['authors'] ??= [];
			$tile['collectionId'] = (int)$document->getCollectionId();

			$this->pushService->sendByTag(
				self::watchTag($documentId),
				self::COMMAND,
				[
					'documentId' => $documentId,
					'event' => $tile,
				],
			);
		}
		catch (\Throwable)
		{
			// Best-effort: a push failure must never affect the already-persisted event write.
		}
	}
}
