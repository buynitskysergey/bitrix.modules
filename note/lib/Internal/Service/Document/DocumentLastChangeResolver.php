<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\EventAuthorRepository;
use Bitrix\Note\Internal\Repository\EventRepository;
use Bitrix\Note\Internal\Service\History\AuthorResolver;
use Bitrix\Note\Internal\Service\User\IdentityColor;

/**
 * Bundles the "last change" snapshot (who + when) into the document bootstrap payload,
 * same spot as DocumentViewsSnapshotResolver — the activity-line chip needs it without
 * a separate listFeed request. Reuses EventRepository::listByEntity() (already keyset/
 * ORM, no new SQL) filtered to a single type and limit=1 to get the latest
 * content_changed event, plus EventAuthorRepository + AuthorResolver for its co-authors
 * — the same repositories FeedProvider uses for the feed itself.
 */
final class DocumentLastChangeResolver
{
	private const EVENT_TYPE_CONTENT_CHANGED = 'content_changed';

	public function __construct(
		private readonly EventRepository $eventRepository = new EventRepository(),
		private readonly EventAuthorRepository $eventAuthorRepository = new EventAuthorRepository(),
		private readonly AuthorResolver $authorResolver = new AuthorResolver(),
	) {}

	/**
	 * @return array{authors: array<int, array{id:int,name:string,avatar:?string,color:string}>, time: string}|null
	 */
	public function resolve(int $documentId): ?array
	{
		if ($documentId <= 0)
		{
			return null;
		}

		$events = $this->eventRepository->listByEntity(
			EventTable::SCOPE_DOCUMENT,
			$documentId,
			[self::EVENT_TYPE_CONTENT_CHANGED],
			null,
			null,
			1,
		);

		if (empty($events))
		{
			return null;
		}

		$event = $events[0];
		$eventId = (int)$event->getId();
		$authorIds = $this->eventAuthorRepository->getAuthorIdsByEventIds([$eventId])[$eventId] ?? [];

		$resolvedAuthors = $this->authorResolver->resolve($authorIds);
		$authors = [];
		foreach ($authorIds as $authorId)
		{
			$authors[] = $resolvedAuthors[$authorId]
				?? ['id' => $authorId, 'name' => '', 'avatar' => null, 'color' => IdentityColor::forUser($authorId)];
		}

		return [
			'authors' => $authors,
			'time' => $event->getCreatedAt()->format('c'),
		];
	}
}
