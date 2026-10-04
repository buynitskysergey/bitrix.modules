<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Im;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Entity\History\Event;
use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\EventAuthorRepository;

/**
 * [P7.T3] Encapsulates every \Bitrix\Im\... call for Block 7 delivery — same
 * isolation pattern as TaskMentionGateway: a Loader::includeModule('im') guard at
 * the top of every public method, silent no-op degradation when im is off on the
 * portal. For every event type EXCEPT content_changed, NOTIFY_TAG collapsing (TAG-01)
 * is left entirely to im's own Notify::deleteOldNotifyByTag (fired automatically inside
 * CIMNotify::Add when NOTIFY_TAG is non-empty). content_changed is deliberately sent
 * WITHOUT a tag: its authorship is a set (b_note_event_author, not the arbitrary compact
 * trigger USER_ID) surfaced via the primary FROM_USER_ID + the PARAMS['USERS'] co-author
 * badge — and a non-empty NOTIFY_TAG would make CIMMessenger::Add overwrite PARAMS['USERS']
 * with its own tag-collapse set, clobbering the real co-author list. The click-through
 * target is NOTIFY_LINK, a relative note URL; the note document/collection page itself
 * re-checks access on load (AC-052/ERR-001), so no separate "recheck on click" controller
 * is needed here.
 */
final class ImNotificationGateway
{
	private const TAG_PREFIX = 'NOTE';
	private const TAG_AGGREGATE = 'AGG';
	private const EVENT_CONTENT_CHANGED = 'content_changed';

	/**
	 * Explicit eventType -> lang key maps, NOT built by string concat. Every key stays a
	 * grep-able literal: translation tooling finds it, and a future per-phrase MSGVER bump
	 * (KEY -> KEY_MSGVER_1) is a one-line map edit instead of special-casing a key builder.
	 * Mirrors NotifySchemaHandler's explicit schema map. Aggregate keys are the getMessagePlural
	 * base (im appends _PLURAL_N).
	 */
	private const MESSAGE_KEYS = [
		'created' => 'NOTE_NOTIFY_MESSAGE_CREATED',
		'content_changed' => 'NOTE_NOTIFY_MESSAGE_CONTENT_CHANGED',
		'title_changed' => 'NOTE_NOTIFY_MESSAGE_TITLE_CHANGED',
		'moved' => 'NOTE_NOTIFY_MESSAGE_MOVED',
		'archived' => 'NOTE_NOTIFY_MESSAGE_ARCHIVED',
		'archive_restored' => 'NOTE_NOTIFY_MESSAGE_ARCHIVE_RESTORED',
		'trashed' => 'NOTE_NOTIFY_MESSAGE_TRASHED',
		'trash_restored' => 'NOTE_NOTIFY_MESSAGE_TRASH_RESTORED',
		'access_changed' => 'NOTE_NOTIFY_MESSAGE_ACCESS_CHANGED',
	];

	private const AGGREGATE_MESSAGE_KEYS = [
		'created' => 'NOTE_NOTIFY_MESSAGE_AGGREGATE_CREATED',
		'content_changed' => 'NOTE_NOTIFY_MESSAGE_AGGREGATE_CONTENT_CHANGED',
		'title_changed' => 'NOTE_NOTIFY_MESSAGE_AGGREGATE_TITLE_CHANGED',
		'moved' => 'NOTE_NOTIFY_MESSAGE_AGGREGATE_MOVED',
		'archived' => 'NOTE_NOTIFY_MESSAGE_AGGREGATE_ARCHIVED',
		'archive_restored' => 'NOTE_NOTIFY_MESSAGE_AGGREGATE_ARCHIVE_RESTORED',
		'trashed' => 'NOTE_NOTIFY_MESSAGE_AGGREGATE_TRASHED',
		'trash_restored' => 'NOTE_NOTIFY_MESSAGE_AGGREGATE_TRASH_RESTORED',
		'access_changed' => 'NOTE_NOTIFY_MESSAGE_AGGREGATE_ACCESS_CHANGED',
	];

	/** @var array<int, string> */
	private array $documentTitleCache = [];
	/** @var array<int, string> */
	private array $collectionNameCache = [];

	public function __construct(
		private readonly DocumentRepository $documentRepository = new DocumentRepository(),
		private readonly CollectionRepository $collectionRepository = new CollectionRepository(),
		private readonly EventAuthorRepository $eventAuthorRepository = new EventAuthorRepository(),
	) {}

	/**
	 * Single-document notification for one dedup'd event group (ALG-03).
	 */
	public function notify(int $recipientId, Event $group): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$documentId = $group->getEntityId();
		$eventType = $group->getEventType();
		$title = $this->resolveDocumentTitle($documentId);
		$link = MentionType::Document->urlFor($documentId);

		if ($eventType === self::EVENT_CONTENT_CHANGED)
		{
			$this->notifyContentChanged($recipientId, $group, $title, $link);

			return;
		}

		// Every other single-document event has one true actor (the USER_ID that performed
		// it), so the arbitrary-trigger problem of content_changed does not apply — keep the
		// NOTIFY_TAG cross-tick collapse for them.
		$tag = self::buildTag($group->getScope(), $eventType, $documentId);
		$authorId = max(0, $group->getUserId());

		\CIMNotify::Add([
			'TO_USER_ID' => $recipientId,
			'FROM_USER_ID' => $authorId,
			'NOTIFY_TYPE' => $authorId > 0 ? IM_NOTIFY_FROM : IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'note',
			'NOTIFY_EVENT' => $eventType,
			'NOTIFY_TAG' => $tag,
			'NOTIFY_SUB_TAG' => $tag . '|' . $recipientId,
			'NOTIFY_LINK' => $link,
			// In-app message links the title; the OUT (mail/push) variant stays plain — im's
			// removeBbCodes() strips [url] there anyway.
			'NOTIFY_MESSAGE' => self::singleMessage($eventType, $title, $link),
			'NOTIFY_MESSAGE_OUT' => self::singleMessage($eventType, $title),
		]);
	}

	/**
	 * content_changed authorship comes solely from b_note_event_author — the set of people
	 * whose patches were folded into the compacted version — NEVER the compact trigger
	 * (USER_ID), who may not even be one of them. The first author drives FROM_USER_ID (the
	 * notification's face); the rest go into the PARAMS['USERS'] co-author badge im renders as
	 * "… и ещё N человек". No NOTIFY_TAG here: a tag would make CIMMessenger::Add overwrite
	 * PARAMS['USERS'] with its tag-collapse set (see class docblock). Losing the cross-tick
	 * collapse is intentional — rung #1 (in-batch dedup) still caps this at one notification
	 * per document per drain window.
	 */
	private function notifyContentChanged(int $recipientId, Event $group, string $title, string $link): void
	{
		$authorIds = $this->resolveContentAuthors((int)$group->getId(), $group->getUserId());
		$primaryAuthorId = $authorIds[0];
		$coAuthorIds = array_slice($authorIds, 1);

		\CIMNotify::Add([
			'TO_USER_ID' => $recipientId,
			'FROM_USER_ID' => $primaryAuthorId,
			'NOTIFY_TYPE' => $primaryAuthorId > 0 ? IM_NOTIFY_FROM : IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'note',
			'NOTIFY_EVENT' => self::EVENT_CONTENT_CHANGED,
			'NOTIFY_LINK' => $link,
			// The co-author badge ("… и ещё N человек"); im reads it from the USERS message param.
			'PARAMS' => $coAuthorIds === [] ? [] : ['USERS' => $coAuthorIds],
			'NOTIFY_MESSAGE' => self::singleMessage(self::EVENT_CONTENT_CHANGED, $title, $link),
			'NOTIFY_MESSAGE_OUT' => self::singleMessage(self::EVENT_CONTENT_CHANGED, $title),
		]);
	}

	/**
	 * Distinct co-author ids for a content_changed event, sorted ascending so the primary
	 * (FROM_USER_ID) pick is deterministic across ticks. Falls back to the event actor only
	 * if the flag table is unexpectedly empty — CompactDocumentCommand writes content_changed
	 * exclusively with a non-empty author set, so the fallback is defensive, not a normal path.
	 *
	 * @return int[] non-empty
	 */
	private function resolveContentAuthors(int $eventId, int $actorId): array
	{
		$ids = $eventId > 0
			? ($this->eventAuthorRepository->getAuthorIdsByEventIds([$eventId])[$eventId] ?? [])
			: [];
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		)));
		sort($ids);

		return $ids !== [] ? $ids : [max(0, $actorId)];
	}

	/**
	 * [AC-051] Aggregate notification — a plan bucket of (recipient, eventType,
	 * collectionId) spanning more than one distinct document collapses into one
	 * "N documents ..." notification instead of N single ones. $groups is never
	 * empty here (the drainer only calls this branch when distinct ENTITY_ID > 1).
	 *
	 * @param Event[] $groups
	 */
	public function notifyAggregate(int $recipientId, string $eventType, int $collectionId, array $groups): void
	{
		if (!Loader::includeModule('im') || empty($groups))
		{
			return;
		}

		$tag = self::buildAggregateTag($eventType, $collectionId);
		$documentCount = count(array_unique(array_map(
			static fn(Event $g): int => $g->getEntityId(),
			$groups,
		)));

		$latest = self::latestGroup($groups);
		$authorId = max(0, $latest->getUserId());
		$collectionName = $this->resolveCollectionName($collectionId);
		$link = MentionType::Collection->urlFor($collectionId);

		\CIMNotify::Add([
			'TO_USER_ID' => $recipientId,
			'FROM_USER_ID' => $authorId,
			'NOTIFY_TYPE' => $authorId > 0 ? IM_NOTIFY_FROM : IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'note',
			'NOTIFY_EVENT' => $eventType,
			'NOTIFY_TAG' => $tag,
			'NOTIFY_SUB_TAG' => $tag . '|' . $recipientId,
			'NOTIFY_LINK' => $link,
			// In-app message links the collection name; OUT stays plain (see notify()).
			'NOTIFY_MESSAGE' => self::aggregateMessage($eventType, $documentCount, $collectionName, $link),
			'NOTIFY_MESSAGE_OUT' => self::aggregateMessage($eventType, $documentCount, $collectionName),
		]);
	}

	/**
	 * @param Event[] $groups non-empty
	 */
	private static function latestGroup(array $groups): Event
	{
		$latest = null;
		foreach ($groups as $group)
		{
			if ($latest === null || $group->getId() > $latest->getId())
			{
				$latest = $group;
			}
		}

		/** @var Event $latest */
		return $latest;
	}

	private function resolveDocumentTitle(int $documentId): string
	{
		if (!isset($this->documentTitleCache[$documentId]))
		{
			$document = $this->documentRepository->getMetaById($documentId, ['ID', 'TITLE']);
			$this->documentTitleCache[$documentId] = $document !== null ? (string)$document->getTitle() : '';
		}

		return $this->documentTitleCache[$documentId];
	}

	private function resolveCollectionName(int $collectionId): string
	{
		if (!isset($this->collectionNameCache[$collectionId]))
		{
			$collection = $this->collectionRepository->getById($collectionId);
			$this->collectionNameCache[$collectionId] = $collection !== null ? (string)$collection->getName() : '';
		}

		return $this->collectionNameCache[$collectionId];
	}

	/**
	 * [TAG-01 / NORMATIVE] Single-document tag form:
	 * NOTE|{SCOPE}|{EVENT_TYPE}|{ENTITY_ID}. Every field comes straight off the
	 * b_note_event row — no extra query.
	 */
	private static function buildTag(string $scope, string $eventType, int $entityId): string
	{
		return implode('|', [self::TAG_PREFIX, $scope, $eventType, $entityId]);
	}

	/**
	 * [TAG-01 / NORMATIVE] Aggregate tag form: NOTE|AGG|{EVENT_TYPE}|{collectionId}.
	 */
	private static function buildAggregateTag(string $eventType, int $collectionId): string
	{
		return implode('|', [self::TAG_PREFIX, self::TAG_AGGREGATE, $eventType, $collectionId]);
	}

	/**
	 * Recipient's own language, resolved by CIMNotify::Add itself when it sees a
	 * Closure in a loc-aware field (im_notify.php::getTextMessageByLang). The
	 * actor's name is intentionally never interpolated here — FROM_USER_ID is the
	 * only actor signal we hand to im, and im renders/guards that name uniformly
	 * for every module (privacy-safe by construction, not by a note-side check).
	 */
	private static function singleMessage(string $eventType, string $title, ?string $link = null): \Closure
	{
		return static function (?string $languageId = null) use ($eventType, $title, $link): string {
			$display = $title !== '' ? $title : Loc::getMessage('NOTE_NOTIFY_UNTITLED', null, $languageId);

			return (string)Loc::getMessage(
				self::messageKey($eventType),
				['#TITLE#' => self::linkToken($display, $link)],
				$languageId,
			);
		};
	}

	private static function aggregateMessage(string $eventType, int $documentCount, string $collectionName, ?string $link = null): \Closure
	{
		return static function (?string $languageId = null) use ($eventType, $documentCount, $collectionName, $link): string {
			$display = $collectionName !== '' ? $collectionName : Loc::getMessage('NOTE_NOTIFY_UNTITLED', null, $languageId);

			return (string)Loc::getMessagePlural(
				self::aggregateMessageKey($eventType),
				$documentCount,
				[
					'#COUNT#' => $documentCount,
					'#COLLECTION#' => self::linkToken($display, $link),
				],
				$languageId,
			);
		};
	}

	/**
	 * Wraps display text in a [url] BBCode when a link is given (in-app NOTIFY_MESSAGE), or
	 * returns it plain otherwise (NOTIFY_MESSAGE_OUT — im's removeBbCodes() would strip the tag
	 * for mail/push anyway). The url target is our own relative /note/... path; only the display
	 * text is user content, and a stray bracket there can at worst end the tag early (cosmetic),
	 * never inject a different target — the same latitude tasks' own notify links take.
	 */
	private static function linkToken(string $display, ?string $link): string
	{
		if ($link === null || $link === '')
		{
			return $display;
		}

		return '[url=' . $link . ']' . $display . '[/url]';
	}

	private static function messageKey(string $eventType): string
	{
		return self::MESSAGE_KEYS[$eventType] ?? '';
	}

	private static function aggregateMessageKey(string $eventType): string
	{
		return self::AGGREGATE_MESSAGE_KEYS[$eventType] ?? '';
	}
}
