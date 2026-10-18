<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import;

use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Service\Import\Source\SourceInterface;

/**
 * Transfers the source collection's access control to the note collection the conversion made.
 * Invoked from CreateCollectionStep once the collection exists - and, on the landing branch, only for
 * a collection that very conversion created. The apply below is a replacement of the whole access of
 * a collection, not an addition to it, so a base that was already there keeps what it holds: that
 * access was given in note, after the base got there, and an update of its content was never an offer
 * to hand it back to the source.
 *
 * Access transfer is best-effort: any failure is logged but never aborts the
 * import (the collection just keeps its default private ACL). The source returns
 * positive grants as Bitrix access codes (e.g. SG{id}_K, G{id}) plus a '*' policy
 * level; the importer is always kept as a moderator so the collection satisfies
 * the "at least one moderator" invariant and whoever ran the migration keeps
 * control. A source with nothing to hand over says so - in a word of its own
 * ([P6.T5]) or by naming neither a grant nor a policy - and the collection then
 * keeps the access it was created with, with the case written to the log.
 *
 * One thing here is NOT best-effort ([P6.T4]): whether the initiator could read the source base at
 * all. That question belongs to the request they started the conversion from and is answered there
 * ({@see \Bitrix\Note\Public\Command\Import\StartImportCommand}), because this class runs inside a
 * background job, where the rights of the source are not the rights of anybody in particular
 * (ALG-03). A base outside that snapshot gets no access transferred and the refusal is written to
 * the log, so it cannot pass for a base that simply had nothing to transfer.
 */
class CollectionAccessTransfer
{
	public function apply(array $option, ?SourceInterface $source): void
	{
		$collectionId = (int)($option['resultCollectionId'] ?? 0);
		$userId = (int)($option['userId'] ?? 0);
		if ($collectionId <= 0 || $userId <= 0 || $source === null)
		{
			return;
		}

		$collectionIds = $option['collectionIds'] ?? [];
		$collectionIndex = $option['collectionIndex'] ?? 0;
		$sourceCollectionId = (string)($collectionIds[$collectionIndex] ?? '');
		if ($sourceCollectionId === '')
		{
			return;
		}

		if (!$this->initiatorCouldReadSource($option, $sourceCollectionId))
		{
			ImportLogger::logError(
				"collectionAccess: {$sourceCollectionId} was not readable by user {$userId} when the "
				. "import started - no access transferred to collection {$collectionId}"
			);

			return;
		}

		$result = $source->getCollectionAccess($sourceCollectionId);
		if (!$result->success)
		{
			ImportLogger::logInfo("collectionAccess: source returned no access for {$sourceCollectionId}");

			return;
		}

		// [P6.T5] The source states "there is nothing to transfer" itself, so an empty answer is not
		// read as one: an empty list on its own says nothing about whether the source was read at all.
		if ($result->data['nothingToTransfer'] ?? false)
		{
			ImportLogger::logInfo(
				"collectionAccess: source {$sourceCollectionId} carries no access of its own - "
				. "collection {$collectionId} keeps its default access"
			);

			return;
		}

		$permissions = $result->data['permissions'] ?? [];
		// [P6.T5] at this seam as well: a null policy is a source saying nothing about one, while
		// `none` is a source saying the base is open to nobody it named - and the wiki locator answers
		// `none` for a base it could not resolve at all. Both leave the collection as closed as it was
		// created, so coercing one into the other here lost the difference for good.
		$policyLevel = $result->data['policyLevel'] ?? null;
		$policyOpensNobody = $policyLevel === null
			|| $policyLevel === CollectionAccessService::LEVEL_CODE_NONE;

		// Nothing to transfer - the collection keeps the access it was created with. A policy above
		// `none` is a transfer of its own even without a single grant (a base open to all users for
		// reading is exactly that), so it does not end up here.
		if ($permissions === [] && $policyOpensNobody)
		{
			ImportLogger::logInfo(
				"collectionAccess: source {$sourceCollectionId} hands over no grant and "
				. ($policyLevel === null ? 'names no policy of its own' : "names the policy {$policyLevel}")
				. " - collection {$collectionId} keeps its default access"
			);

			return;
		}

		// The importer must stay a moderator: replaceCollectionPermissions requires
		// at least one moderator, and the importer owns the migrated collection.
		$permissions[] = [
			'subjectCode' => 'U' . $userId,
			'level' => CollectionAccessService::LEVEL_CODE_MODERATE,
		];

		$applyResult = CollectionAccessService::replaceCollectionPermissions(
			$collectionId,
			$permissions,
			$userId,
			// The one place that has to hold a level: a source that named no policy gets the level a
			// new collection already carries, so nothing about it changes.
			$policyLevel ?? CollectionAccessService::LEVEL_CODE_NONE,
		);

		if (!$applyResult->isSuccess())
		{
			ImportLogger::logError(
				"collectionAccess: failed for collection {$collectionId}: "
				. implode('; ', $applyResult->getErrorMessages())
			);

			return;
		}

		ImportLogger::logInfo(
			'collectionAccess: applied ' . count($permissions) . " grants to collection {$collectionId}"
		);
	}

	/**
	 * Whether the base being converted was among those the initiator could read when they started
	 * the conversion (ALG-03). The rule itself lives in {@see SourceReadableSnapshot}, and the steps
	 * that read the structure and the content of the base ask it the same way: what may be read at
	 * all is one question and it has one answer.
	 */
	private function initiatorCouldReadSource(array $option, string $sourceCollectionId): bool
	{
		return SourceReadableSnapshot::allows($option, $sourceCollectionId);
	}
}
