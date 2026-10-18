<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Im;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Notification\CompactedEventGroup;
use Bitrix\Note\Internal\Service\Notification\EventAuthorResolver;

/**
 * Encapsulates every \Bitrix\Im\... call for Block 7 delivery - same
 * isolation pattern as TaskMentionGateway: a Loader::includeModule('im') guard at
 * the top of every public method, silent no-op degradation when im is off on the
 * portal. A delivery is keyed by the notification KIND the compacted group carries rather than by
 * a journal event type: an edit and a rename of the same article read
 * as one notification, so both must land on one NOTIFY_TAG to keep collapsing across ticks
 * (TAG-01) - the collapse itself is im's own Notify::deleteOldNotifyByTag, fired inside
 * CIMNotify::Add whenever NOTIFY_TAG is non-empty. The co-author badge PARAMS['USERS'] is
 * deliberately never sent: on a tag collapse CIMMessenger::Add overwrites it with its own
 * collapse set, so passing ours would only pretend the badge is under our control. The
 * authorship signal we do own is FROM_USER_ID, taken from the co-author journal
 * (b_note_event_author) instead of the arbitrary compact trigger USER_ID, and picked per
 * recipient: of the authors a compacted group carries, the face is one that is not the recipient
 * themselves (EventAuthorResolver::resolveFace). The click-through target is NOTIFY_LINK, a
 * relative note URL; the note document/collection page itself re-checks access on load, so no
 * separate "recheck on click" controller is needed here.
 *
 * Both delivery methods report whether the drain cursor may pass the group, and the two ways a
 * send can go wrong are told apart on purpose: im's own documented `false` is a refusal that makes
 * the drainer hold its cursor and resend, while a throwable out of the im call leaves the outcome
 * unknown and is counted as delivered (see send()).
 */
final class ImNotificationGateway
{
	private const TAG_PREFIX = 'NOTE';
	private const TAG_AGGREGATE = 'AGG';

	/**
	 * Explicit kind -> lang key maps, NOT built by string concat. Every key stays a
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
		private readonly EventAuthorResolver $eventAuthorResolver = new EventAuthorResolver(),
	) {}

	/**
	 * Single-document notification for one compacted event group (ALG-03).
	 *
	 * @return bool false only when im accepted the call and refused the message (see send()); a
	 *              portal without im degrades silently and reads as delivered — retrying that
	 *              forever would stall the drain cursor instead of the delivery.
	 */
	public function notify(int $recipientId, CompactedEventGroup $group): bool
	{
		if (!Loader::includeModule('im'))
		{
			return true;
		}

		$documentId = $group->getEntityId();
		$kind = $group->getKind();
		$title = $this->resolveDocumentTitle($documentId);
		$link = MentionType::Document->urlFor($documentId);

		$tag = self::buildTag($group->getScope(), $kind, $documentId);
		$authorId = $this->eventAuthorResolver->resolveFace($group, $recipientId);

		return self::send([
			'TO_USER_ID' => $recipientId,
			'FROM_USER_ID' => $authorId,
			'NOTIFY_TYPE' => $authorId > 0 ? IM_NOTIFY_FROM : IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'note',
			'NOTIFY_EVENT' => $kind,
			'NOTIFY_TAG' => $tag,
			'NOTIFY_SUB_TAG' => $tag . '|' . $recipientId,
			'NOTIFY_LINK' => $link,
			// In-app message links the title; the OUT (mail/push) variant stays plain — im's
			// removeBbCodes() strips [url] there anyway.
			'NOTIFY_MESSAGE' => self::singleMessage($kind, $title, $link),
			'NOTIFY_MESSAGE_OUT' => self::singleMessage($kind, $title),
		]);
	}

	/**
	 * Aggregate notification - a plan bucket of (recipient, notification kind,
	 * collectionId) spanning more than one distinct document collapses into one
	 * "N documents ..." notification instead of N single ones. $groups is never
	 * empty here (the drainer only calls this branch when distinct ENTITY_ID > 1).
	 *
	 * @param CompactedEventGroup[] $groups
	 * @return bool see notify()
	 */
	public function notifyAggregate(int $recipientId, string $kind, int $collectionId, array $groups): bool
	{
		if (!Loader::includeModule('im') || empty($groups))
		{
			return true;
		}

		$tag = self::buildAggregateTag($kind, $collectionId);
		$documentCount = count(array_unique(array_map(
			static fn(CompactedEventGroup $g): int => $g->getEntityId(),
			$groups,
		)));

		// Same authorship rule as the single path: the face is an author of the latest group who is
		// not the recipient, never the compact trigger that happened to fire it.
		$authorId = $this->eventAuthorResolver->resolveFace(self::latestGroup($groups), $recipientId);
		$collectionName = $this->resolveCollectionName($collectionId);
		$link = MentionType::Collection->urlFor($collectionId);

		return self::send([
			'TO_USER_ID' => $recipientId,
			'FROM_USER_ID' => $authorId,
			'NOTIFY_TYPE' => $authorId > 0 ? IM_NOTIFY_FROM : IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'note',
			'NOTIFY_EVENT' => $kind,
			'NOTIFY_TAG' => $tag,
			'NOTIFY_SUB_TAG' => $tag . '|' . $recipientId,
			'NOTIFY_LINK' => $link,
			// In-app message links the collection name; OUT stays plain (see notify()).
			'NOTIFY_MESSAGE' => self::aggregateMessage($kind, $documentCount, $collectionName, $link),
			'NOTIFY_MESSAGE_OUT' => self::aggregateMessage($kind, $documentCount, $collectionName),
		]);
	}

	/**
	 * Hands one prepared payload to im and answers whether the drain cursor may pass the group.
	 *
	 * The two ways this can go wrong are not symmetric. `false` is im's own documented refusal,
	 * reported before anything is stored: nothing was delivered, and the drainer holds its cursor
	 * for a resend. A throwable out of Add() leaves the outcome unknown, and in practice usually
	 * means the opposite — the notify message is already on record and what failed is the work im
	 * does around the write (chat counters, its persistent chat cache, the pull event), or a portal
	 * error handler that raises a warning from that work as an exception. Reading that as a refusal
	 * would resend every notification of the pass on every tick until the attempt budget runs out,
	 * so it is logged and counted as delivered: a success misread as failure costs
	 * MAX_DELIVERY_ATTEMPTS copies of everything the pass carried, a failure misread as success
	 * costs one message.
	 */
	private static function send(array $payload): bool
	{
		try
		{
			return \CIMNotify::Add($payload) !== false;
		}
		catch (\Throwable $e)
		{
			self::logUnknownOutcome($payload, $e);

			return true;
		}
	}

	/**
	 * Logged here rather than reported to the drainer: the drainer writes down what it decides to
	 * retry, and this is the one outcome nothing retries — without a record of its own it would be
	 * the only silent one.
	 */
	private static function logUnknownOutcome(array $payload, \Throwable $e): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_WARNING,
			'AUDIT_TYPE_ID' => 'NOTE_NOTIFY_SEND_UNKNOWN',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => 'im threw on note notification ' . ($payload['NOTIFY_TAG'] ?? '')
				. ' for user ' . ($payload['TO_USER_ID'] ?? 0) . '; the message may have been'
				. ' stored, so it is counted as delivered: ' . $e->getMessage(),
		]);
	}

	/**
	 * @param CompactedEventGroup[] $groups non-empty
	 */
	private static function latestGroup(array $groups): CompactedEventGroup
	{
		$latest = null;
		foreach ($groups as $group)
		{
			if ($latest === null || $group->getRepresentative()->getId() > $latest->getRepresentative()->getId())
			{
				$latest = $group;
			}
		}

		/** @var CompactedEventGroup $latest */
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
	 * Single-document tag form: NOTE|{SCOPE}|{KIND}|{ENTITY_ID}. Scope and entity id come
	 * straight off the b_note_event row, the kind off the shipping policy — no extra query.
	 * Keying the middle segment by kind rather than by event type is what lets an edit and a
	 * rename of one article collapse into a single notification across ticks.
	 */
	private static function buildTag(string $scope, string $kind, int $entityId): string
	{
		return implode('|', [self::TAG_PREFIX, $scope, $kind, $entityId]);
	}

	/**
	 * Aggregate tag form: NOTE|AGG|{KIND}|{collectionId}.
	 */
	private static function buildAggregateTag(string $kind, int $collectionId): string
	{
		return implode('|', [self::TAG_PREFIX, self::TAG_AGGREGATE, $kind, $collectionId]);
	}

	/**
	 * Recipient's own language, resolved by CIMNotify::Add itself when it sees a
	 * Closure in a loc-aware field (im_notify.php::getTextMessageByLang). The
	 * actor's name is intentionally never interpolated here — FROM_USER_ID is the
	 * only actor signal we hand to im, and im renders/guards that name uniformly
	 * for every module (privacy-safe by construction, not by a note-side check).
	 */
	private static function singleMessage(string $kind, string $title, ?string $link = null): \Closure
	{
		return static function (?string $languageId = null) use ($kind, $title, $link): string {
			$display = $title !== '' ? $title : Loc::getMessage('NOTE_NOTIFY_UNTITLED', null, $languageId);

			return (string)Loc::getMessage(
				self::messageKey($kind),
				['#TITLE#' => self::linkToken($display, $link)],
				$languageId,
			);
		};
	}

	private static function aggregateMessage(string $kind, int $documentCount, string $collectionName, ?string $link = null): \Closure
	{
		return static function (?string $languageId = null) use ($kind, $documentCount, $collectionName, $link): string {
			$display = $collectionName !== '' ? $collectionName : Loc::getMessage('NOTE_NOTIFY_UNTITLED', null, $languageId);

			return (string)Loc::getMessagePlural(
				self::aggregateMessageKey($kind),
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
	 * for mail/push anyway). The url target is our own relative /note/... path, but the display
	 * text is an article title or a knowledge base name, so it is neutralized before either
	 * branch: a bracket left in it closes our tag early and opens whatever its author typed
	 * next — an [img] every recipient's browser fetches on its own, or a second [url] aimed
	 * anywhere.
	 */
	private static function linkToken(string $display, ?string $link): string
	{
		$display = self::neutralizeBbCode($display);

		if ($link === null || $link === '')
		{
			return $display;
		}

		return '[url=' . $link . ']' . $display . '[/url]';
	}

	/**
	 * Brackets are replaced rather than entity-encoded: Text::parse() turns &#91; back into '['
	 * before the text reaches a client (im/lib/text.php), so encoding would only postpone the
	 * injection. Same treatment im itself gives a user-chosen file name inside a message block
	 * (im V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock).
	 */
	private static function neutralizeBbCode(string $display): string
	{
		return str_replace(['[', ']'], ['(', ')'], $display);
	}

	private static function messageKey(string $kind): string
	{
		return self::MESSAGE_KEYS[$kind] ?? '';
	}

	private static function aggregateMessageKey(string $kind): string
	{
		return self::AGGREGATE_MESSAGE_KEYS[$kind] ?? '';
	}
}
