<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Access\Service;

/**
 * Answers one question about a collection: is it open to the portal or is it somebody's own.
 *
 * The answer has always lived in the access table, in the row with the `'*'` subject - the one that
 * says what an arbitrary employee may do here. The `POLICY_LEVEL` column of the collection row looks
 * like the answer but is never written to, so anything read from it is the same value for every
 * collection on the portal.
 *
 * A `'*'` row exists for every collection - it is written at creation with the level "nothing" - so
 * the presence of the row means nothing on its own. What matters is its level: from view upwards the
 * collection is open to everyone, below that it is private.
 */
final class CollectionPolicyResolver
{
	public const POLICY_PRIVATE = 'private';
	public const POLICY_PORTAL = 'portal';

	public function resolve(int $collectionId): string
	{
		return self::codeFromLevel(CollectionAccessService::getCollectionPolicyLevel($collectionId));
	}

	/**
	 * @param int[] $collectionIds
	 * @return array<int, string> policy code keyed by collection id
	 */
	public function resolveBatch(array $collectionIds): array
	{
		$levels = CollectionAccessService::batchGetPolicyLevels($collectionIds);

		$result = [];
		foreach ($collectionIds as $collectionId)
		{
			$collectionId = (int)$collectionId;
			$result[$collectionId] = self::codeFromLevel($levels[$collectionId] ?? CollectionAccessService::LEVEL_NONE);
		}

		return $result;
	}

	public static function codeFromLevel(int $policyLevel): string
	{
		return $policyLevel >= CollectionAccessService::LEVEL_VIEW ? self::POLICY_PORTAL : self::POLICY_PRIVATE;
	}
}
