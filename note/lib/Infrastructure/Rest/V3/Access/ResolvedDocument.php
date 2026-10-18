<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Access;

use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Model\Document;

/**
 * The answer of {@see DocumentStateResolver}: the document, the state it is in and whether the caller
 * may write to it. Holding the meta object here is what keeps the callers from reading the same row a
 * second time.
 */
final class ResolvedDocument
{
	public function __construct(
		public readonly Document $meta,
		public readonly DocumentState $state,
		public readonly bool $canWrite,
		public readonly bool $sharedAccess,
		public readonly ?RecycleBinRecord $recycleBinRecord = null,
	) {}

	public function getId(): int
	{
		return (int)$this->meta->getId();
	}

	public function getCollectionId(): int
	{
		return (int)$this->meta->getCollectionId();
	}

	public function isArchived(): bool
	{
		return $this->state === DocumentState::Archived;
	}

	public function isTrashed(): bool
	{
		return $this->state === DocumentState::Trashed || $this->state === DocumentState::Orphan;
	}

	public function isOrphan(): bool
	{
		return $this->state === DocumentState::Orphan;
	}
}
