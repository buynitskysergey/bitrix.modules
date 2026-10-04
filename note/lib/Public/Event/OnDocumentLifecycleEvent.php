<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Event;

use Bitrix\Main\Event;

/**
 * EVENT-NOTE-01 — note document lifecycle backend-event.
 *
 * The published contract for note document lifecycle: subscribers register on
 * `('note', self::EVENT_NAME)` and read the payload via {@see \Bitrix\Main\Event::getParameters()}.
 * The reason literals below are part of that contract; subscribers decide the delivery
 * semantics (e.g. "archive == removal") on their side — the payload is subscriber-agnostic.
 *
 * A cascade (subtree archive/delete, collection delete, subtree move) is a SINGLE
 * event carrying the full documentIds list.
 */
final class OnDocumentLifecycleEvent extends Event
{
	public const MODULE_ID = 'note';
	public const EVENT_NAME = 'onDocumentLifecycle';

	public const CREATED = 'created';
	public const ARCHIVED = 'archived';
	public const DELETED = 'deleted';
	public const HARD_DELETED = 'hardDeleted';
	public const RESTORED = 'restored';
	public const COLLECTION_DELETED = 'collectionDeleted';
	public const MOVED = 'moved';

	/**
	 * @param string $reason One of the lifecycle reason constants above.
	 * @param int|null $collectionId Document collection of the event; for `moved` — the NEW
	 *                               collection; null only for `hardDeleted` (the row is already gone).
	 * @param int[] $documentIds Full list of affected document ids (normalized here: intval + reindex).
	 * @param array{collectionId: int, parentId: int|null}|null $from Only for `moved` — old root location.
	 * @param array{collectionId: int, parentId: int|null}|null $to Only for `moved` — new root location.
	 */
	public function __construct(
		string $reason,
		?int $collectionId,
		array $documentIds,
		?array $from = null,
		?array $to = null,
	)
	{
		$payload = [
			'reason' => $reason,
			'collectionId' => $collectionId,
			'documentIds' => array_values(array_map('intval', $documentIds)),
		];
		if ($from !== null)
		{
			$payload['from'] = $from;
		}
		if ($to !== null)
		{
			$payload['to'] = $to;
		}

		parent::__construct(self::MODULE_ID, self::EVENT_NAME, $payload);
	}

	/**
	 * Normalized affected document ids. Used by the publisher to skip emission of an empty cascade.
	 *
	 * @return int[]
	 */
	public function getDocumentIds(): array
	{
		return $this->getParameter('documentIds') ?? [];
	}
}
