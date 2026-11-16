<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Note\Internal\Repository\DocumentRepository;

/**
 * Which knowledge base a level request is answered from.
 *
 * A level is asked for either by base - the top of a section - or by node, the children of one
 * document, and nothing stops a caller from naming both. Naming both is where the rights went
 * missing: they were checked against the base in the request while the rows were read by the node,
 * so a reader holding a base of their own could ask for the children of a node in somebody else's
 * and get them, with ids, titles, excerpts and authors of documents they may not see.
 *
 * The node decides. It is what the rows are read by, so it is what the rights must be read by, and
 * a request that names a base other than the node's own is not corrected into one of them - it is
 * refused, because either half of it may be the lie.
 *
 * Expects a Bitrix\Main\Engine\Controller host.
 */
trait LevelScopeTrait
{
	/** Two different bases named at once - see the note above on why this is a refusal. */
	private const LEVEL_SCOPE_CONFLICT = -1;

	/**
	 * @return int the base the level answers to; 0 when it answers to no base at all (a node whose
	 *         base is gone - the technical section, whose rows answer to the per-entry rights of the
	 *         surface), or self::LEVEL_SCOPE_CONFLICT when the request names two of them
	 */
	private function resolveLevelScopeCollectionId(?int $collectionId, ?int $parentId): int
	{
		$named = ($collectionId !== null && $collectionId > 0) ? $collectionId : 0;
		if ($parentId === null || $parentId <= 0)
		{
			return $named;
		}

		$ofParent = $this->collectionIdOfDocument($parentId);
		if ($ofParent <= 0)
		{
			return 0;
		}

		if ($named > 0 && $named !== $ofParent)
		{
			return self::LEVEL_SCOPE_CONFLICT;
		}

		return $ofParent;
	}

	private function collectionIdOfDocument(?int $documentId): int
	{
		if ($documentId === null || $documentId <= 0)
		{
			return 0;
		}

		$location = (new DocumentRepository())->getLocationById($documentId);

		return $location === null ? 0 : (int)$location['collectionId'];
	}
}
