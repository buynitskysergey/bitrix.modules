<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Note\Internal\Exceptions\CollectionTreeTooLargeException;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Document\TreeNestingBuilder;
use Bitrix\Note\Internal\Service\Document\TreeSizeLimit;

/**
 * Reads the tree of the one knowledge base of a public link, and of no other.
 *
 * The reader is built only from the link context, and there is no method taking a knowledge base:
 * an action added later cannot ask for someone else's tree. TreeProvider is deliberately not
 * inherited - its methods take an arbitrary knowledge base, and an isolation that rests on the
 * discipline of a descendant is not an isolation.
 *
 * The readability check of the ordinary path is not repeated and not relaxed: the link has already
 * been checked by the filter, while assertCollectionReadable() would refuse a visitor always.
 */
final class ScopedTreeReader
{
	/**
	 * How much of a knowledge base one anonymous request may read.
	 *
	 * The budget of the answer is TreeSizeLimit::MAX_NODES, but truncateByRoots() needs more than
	 * the budget to work from: it keeps whole subtrees of the first roots, and a child of an early
	 * root can stand anywhere in the listing, so the reader has to hold the whole forest to let the
	 * builder decide. The ceiling is therefore several times the budget - wide enough that every base
	 * able to produce an answer today still produces the same one, and narrow enough that a request
	 * arriving from the internet cannot make the portal materialise a knowledge base of any size.
	 *
	 * Above the ceiling the answer is a refusal and not a piece: a listing cut on the way out of the
	 * database is not the top of the tree but a forest missing the children of its last parents, and
	 * a visitor cannot tell that from a knowledge base that really looks like that. What the refused
	 * visitor still has is the paged list of articles, which is exactly why it pages.
	 */
	private const TREE_READ_CEILING = TreeSizeLimit::MAX_NODES * 4;

	public function __construct(
		private readonly PublicScope $scope,
		private readonly DocumentRepository $repository = new DocumentRepository(),
		private readonly TreeNestingBuilder $nestingBuilder = new TreeNestingBuilder(),
	) {}

	/**
	 * @return array{items: array[], truncated: bool}
	 * @throws CollectionTreeTooLargeException when the knowledge base is over the read ceiling
	 */
	public function getTree(): array
	{
		[$nodes, $rootIds] = $this->readNodes();

		$truncated = false;
		if (count($nodes) > TreeSizeLimit::MAX_NODES)
		{
			[$nodes, $rootIds, $truncated] = $this->nestingBuilder->truncateByRoots(
				$nodes,
				$rootIds,
				TreeSizeLimit::MAX_NODES,
			);
		}

		return [
			'items' => $this->nestingBuilder->nestNodes($nodes, $rootIds),
			'truncated' => $truncated,
		];
	}

	/**
	 * The node of the public answer carries its own white list - the knowledge base of the ordinary
	 * node is absent from it, and the listing already comes with the whole predicate of a live
	 * document: no archived, no recycled and no hidden main one.
	 *
	 * updatedAt is the date of the last change and nothing else: the visitor is allowed the date and
	 * not the author of it, so no field about who changed the document is read or built. The format is
	 * the one DescriptionController.get already answers with (DateTime::format('c')) - one feature may
	 * not answer the same question in two shapes.
	 *
	 * @return array{0: array<int, array>, 1: int[]} flat nodes keyed by id, and the ids of the roots
	 * @throws CollectionTreeTooLargeException when the knowledge base is over the read ceiling
	 */
	private function readNodes(): array
	{
		$collectionId = $this->scope->getCollectionId();
		if ($this->repository->countLiveInCollection($collectionId) > self::TREE_READ_CEILING)
		{
			throw new CollectionTreeTooLargeException(self::TREE_READ_CEILING);
		}

		$nodes = [];
		foreach ($this->repository->getLiveTreeInCollection($collectionId) as $row)
		{
			$id = (int)$row['ID'];
			$parentId = $row['PARENT_ID'] === null ? null : (int)$row['PARENT_ID'];
			$nodes[$id] = [
				'id' => $id,
				'parentId' => $parentId,
				'title' => (string)($row['TITLE'] ?? ''),
				'position' => (int)($row['POSITION'] ?? 0),
				'updatedAt' => $row['UPDATED_AT']->format('c'),
				'children' => [],
			];
		}

		$rootIds = [];
		foreach ($nodes as $id => $node)
		{
			if ($node['parentId'] !== null && isset($nodes[$node['parentId']]))
			{
				continue;
			}

			// A node whose parent is out of the scope is a root of what the visitor sees, and it
			// loses the id of that parent: an id answering to no node of the answer would still
			// confirm that a document the visitor may not reach exists. Beyond the scope of
			// a link nothing may be told apart from what does not exist at all.
			$nodes[$id]['parentId'] = null;
			$rootIds[] = $id;
		}

		return [$nodes, $rootIds];
	}
}
