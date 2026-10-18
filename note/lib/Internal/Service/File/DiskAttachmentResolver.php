<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\File;

use Bitrix\Note\Internal\Integration\Disk\DiskGateway;

/**
 * Turns `[[disk ...]]` object ids into what the editor needs to draw an attachment: the current name,
 * size, links and viewer attributes. None of that lives in markdown — the token carries only the id,
 * so a renamed file shows its new name and a lost one shows as unavailable instead of lying.
 *
 * Two grounds make an object resolvable, and Disk answers both:
 *  - it is attached to this document — the normal state, and the one that survives ACL changes;
 *  - the viewer has their own right on the file — the only ground right after a pick, before the
 *    content settles and the attachment is created. Without it a just-inserted file would flash as
 *    unavailable to the very person who chose it.
 *
 * Both grounds yield the same field set on purpose: a reader must not be able to tell an attached
 * file from a not-yet-attached one.
 */
final class DiskAttachmentResolver
{
	public function __construct(
		private readonly DiskGateway $diskGateway = new DiskGateway(),
	)
	{
	}

	/**
	 * @param int[] $objectIds
	 * @return array<int, array<string, mixed>> DTO-01 items, in the order of the requested ids
	 */
	public function resolve(int $documentId, array $objectIds): array
	{
		$objectIds = $this->normalizeIds($objectIds);
		if ($documentId <= 0 || $objectIds === [])
		{
			return [];
		}

		$attachedIdByObjectId = [];
		foreach ($this->diskGateway->listAttachments($documentId, $objectIds) as $objectId => $attachedObjectIds)
		{
			// The oldest one — the very row the projection sync keeps when it collapses duplicates. Both
			// answer the permission question identically, but they build different urls, and a url that
			// changes between requests throws away the browser's copy of the file for nothing.
			$attachedIdByObjectId[$objectId] = min($attachedObjectIds);
		}

		$attributes = $this->diskGateway->loadAttributes($objectIds, $attachedIdByObjectId);

		$items = [];
		foreach ($objectIds as $objectId)
		{
			$items[] = isset($attributes[$objectId])
				? ['objectId' => $objectId, 'available' => true, ...$attributes[$objectId]]
				// Nothing but the id: an unresolvable attachment must not leak the file's name or size.
				: ['objectId' => $objectId, 'available' => false]
			;
		}

		return $items;
	}

	/**
	 * @param int[] $objectIds
	 * @return int[]
	 */
	private function normalizeIds(array $objectIds): array
	{
		$ids = array_map('intval', $objectIds);
		$ids = array_filter($ids, static fn(int $id): bool => $id > 0);

		return array_values(array_unique($ids));
	}
}
