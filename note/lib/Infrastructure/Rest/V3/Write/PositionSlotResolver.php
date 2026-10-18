<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Write;

use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;

/**
 * Translates the sort weight a REST caller asks for into the sibling slot the move commands speak in.
 *
 * The two numbers are deliberately not the same thing. Answers carry the weight of the row - sparse
 * values with room between them, the thing the listings are sorted by - and that is what a caller has
 * in hand when it decides where the entity should end up. The commands, written for the drag of a
 * mouse, take the ordinal place in the list instead. Asking REST callers to count places would mean
 * asking them for a number no answer ever gave them, so the translation happens here: the requested
 * weight is looked up among the weights of the future siblings, and its place in that order is the
 * slot.
 *
 * The weight that ends up stored may differ from the one asked for - the commands recompute it from
 * the neighbours and renumber the branch when nothing fits between them - so every action answers with
 * the entity as it was saved rather than echoing the request.
 *
 * The neighbours are read here and read again by the command under its own branch lock, so a move that
 * races another one can land a place off from where its weight pointed. That is the same answer the
 * web gives to two people dragging in the same list at once, and the order that comes out of it is a
 * valid order - which is why this read is not held under a lock of its own.
 */
final class PositionSlotResolver
{
	public function __construct(
		private readonly DocumentRepository $documents = new DocumentRepository(),
		private readonly CollectionRepository $collections = new CollectionRepository(),
	) {}

	public function forDocument(int $collectionId, ?int $parentId, int $movedDocumentId, ?int $weight): ?int
	{
		if ($weight === null)
		{
			return null;
		}

		$weights = [];
		foreach ($this->documents->getBranchPositions($collectionId, $parentId) as $sibling)
		{
			if ($sibling['id'] === $movedDocumentId)
			{
				continue;
			}

			$weights[] = $sibling['position'];
		}

		return $this->slotOf($weights, $weight);
	}

	public function forCollection(int $movedCollectionId, ?int $weight): ?int
	{
		if ($weight === null)
		{
			return null;
		}

		$weights = [];
		foreach ($this->collections->getActivePositions() as $row)
		{
			if ((int)$row['id'] === $movedCollectionId)
			{
				continue;
			}

			$weights[] = (int)$row['position'];
		}

		return $this->slotOf($weights, $weight);
	}

	/**
	 * @param int[] $siblingWeights weights of the future neighbours, heaviest first
	 */
	private function slotOf(array $siblingWeights, int $weight): int
	{
		$slot = 1;
		foreach ($siblingWeights as $siblingWeight)
		{
			// A neighbour of exactly the same weight is not jumped over: equal weights are ordered by
			// id, and the caller asking for that weight means "here", not "below whatever is here".
			if ($siblingWeight <= $weight)
			{
				break;
			}

			$slot++;
		}

		return $slot;
	}
}
