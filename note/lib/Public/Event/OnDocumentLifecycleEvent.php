<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Event;

use Bitrix\Main\Event;
use Bitrix\Note\Internal\Enum\WriteSource;

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
 *
 * Two optional fields extend the payload; a subscriber that ignores them sees no change:
 * - `initiatorId` — who performed the write, present whenever the command knows the user
 *   (`0` is the module's system user, a known actor rather than an absent one);
 * - `writeSource` — a {@see WriteSource} value as a string, ALWAYS present: "source unknown" and
 *   "written by a user" mean the same to a subscriber, so the default is stated explicitly.
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
	// Stays apart from COLLECTION_DELETED, which keeps its meaning "went to the recycle bin".
	public const COLLECTION_HARD_DELETED = 'collectionHardDeleted';
	public const MOVED = 'moved';

	/**
	 * @param string $reason One of the lifecycle reason constants above.
	 * @param int|null $collectionId Document collection of the event; for `moved` — the NEW
	 *                               collection; null only for `hardDeleted` (the row is already gone).
	 * @param int[] $documentIds Full list of affected document ids (normalized here: intval + reindex);
	 *                           empty for `collectionHardDeleted`, which speaks of the base itself.
	 * @param array{collectionId: int, parentId: int|null}|null $from Only for `moved` — old root location.
	 * @param array{collectionId: int, parentId: int|null}|null $to Only for `moved` — new root location.
	 * @param int|null $initiatorId Who performed the write; null when the command has no user.
	 * @param WriteSource|null $writeSource Where the write came from; null means the default source.
	 */
	public function __construct(
		string $reason,
		?int $collectionId,
		array $documentIds,
		?array $from = null,
		?array $to = null,
		?int $initiatorId = null,
		?WriteSource $writeSource = null,
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
		if ($initiatorId !== null)
		{
			$payload['initiatorId'] = $initiatorId;
		}
		// Stored as the enum's string: the payload outlives the request through serialization.
		$payload['writeSource'] = ($writeSource ?? WriteSource::User)->value;

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

	public function getInitiatorId(): ?int
	{
		$initiatorId = $this->getParameter('initiatorId');

		return $initiatorId === null ? null : (int)$initiatorId;
	}

	public function getWriteSource(): string
	{
		return (string)($this->getParameter('writeSource') ?? WriteSource::User->value);
	}
}
