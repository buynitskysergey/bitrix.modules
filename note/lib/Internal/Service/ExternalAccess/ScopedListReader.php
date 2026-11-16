<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Note\Internal\Model\Document;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Document\DocumentCardMetaResolver;

/**
 * Reads the lists of articles of the one knowledge base of a public link, and of no other: the flat
 * list of the whole base behind the link, and a page of the children of one of its documents.
 *
 * What a visitor gets is the ordinary list of the module and not a shape of its own: the same
 * predicate of a live document, the same order, the same paging and the same preview under the
 * title. Only the author is dropped - employees of the portal are not shown outside it - and with
 * it the favourite star, which marks a personal list a visitor does not have.
 *
 * Both lists used to be built on the client out of the tree of the link. The tree carries no
 * preview - it is read for the panel, where a preview would be dead weight on every node of the
 * base - so the cards of a visitor stood without one while the same cards inside the portal had it.
 *
 * Built only from the link context, with no method taking a knowledge base: an action added later
 * cannot ask for someone else's list. The readability check of the ordinary path is not repeated
 * and not relaxed - the link has already been checked by the filter of the namespace.
 */
final class ScopedListReader
{
	private const MAX_LIMIT = 200;
	private const DEFAULT_LIMIT = 50;

	public function __construct(
		private readonly PublicScope $scope,
		private readonly DocumentRepository $repository = new DocumentRepository(),
		private readonly DocumentCardMetaResolver $cardMetaResolver = new DocumentCardMetaResolver(),
	) {}

	/**
	 * @param array{position?: mixed, id?: mixed}|null $afterCursor keyset cursor of a previous answer,
	 *   handed back verbatim
	 * @return array{items: array<int, array{id: int, title: string, position: int, updatedAt: string,
	 *   excerpt: string}>, nextCursor: array{position: int, id: int}|null}
	 */
	public function getList(int $limit = self::DEFAULT_LIMIT, ?array $afterCursor = null): array
	{
		$normalizedLimit = max(1, min(self::MAX_LIMIT, $limit));
		[$afterPosition, $afterId] = $this->readCursor($afterCursor);

		$rawDocuments = $this->repository->listByCollectionFlat(
			$this->scope->getCollectionId(),
			null,
			$normalizedLimit + 1,
			$afterPosition,
			$afterId,
		);

		$hasNextPage = count($rawDocuments) > $normalizedLimit;
		$documents = $hasNextPage ? array_slice($rawDocuments, 0, $normalizedLimit) : $rawDocuments;

		$items = [];
		$lastPosition = null;
		$lastId = null;
		foreach ($documents as $document)
		{
			$id = (int)$document->getId();
			$lastPosition = (int)$document->getPosition();
			$lastId = $id;

			$items[] = [
				'id' => $id,
				'title' => (string)$document->getTitle(),
				'position' => $lastPosition,
				'updatedAt' => $this->observedUpdatedAt($document)->format('c'),
				'excerpt' => '',
			];
		}

		return [
			'items' => $this->withExcerpts($items),
			'nextCursor' => ($hasNextPage && $lastId !== null)
				? ['position' => $lastPosition, 'id' => $lastId]
				: null
			,
		];
	}

	/**
	 * The preview of a page of rows, read in one batch by the same resolver the ordinary list uses -
	 * so a card reads the same on both sides of the link. The resolver also answers with the author,
	 * and that half of its answer is dropped here rather than merely left out of the response: a
	 * visitor is never told who wrote what.
	 *
	 * @param array<int, array> $items
	 * @return array<int, array>
	 */
	private function withExcerpts(array $items): array
	{
		if ($items === [])
		{
			return $items;
		}

		// The resolver is keyed by "document => author", and the author is what it needs the key for.
		// A zero says there is nobody to resolve, and only the preview comes back.
		$authorIdByDocumentId = [];
		foreach ($items as $item)
		{
			$authorIdByDocumentId[(int)$item['id']] = 0;
		}

		$cardMeta = $this->cardMetaResolver->resolve($authorIdByDocumentId);

		foreach ($items as $index => $item)
		{
			$items[$index]['excerpt'] = (string)($cardMeta[(int)$item['id']]['excerpt'] ?? '');
		}

		return $items;
	}

	/**
	 * A page of the children of one document of the base behind the link - the block of child
	 * articles inside an article and the branch the panel opens.
	 *
	 * The row is the ordinary row of a branch (TreeProvider::getListByParent) minus the two halves a
	 * visitor has no business with: the author, whom the portal never shows outside itself, and the
	 * favourite star, which marks a personal list he does not have. Everything else - the preview, the
	 * date, the "has nested" flag and the paging - is the same, so a card of a child article reads the
	 * same on both sides of the link.
	 *
	 * The branch used to be flattened on the client out of the tree of the link, and the tree carries
	 * no preview: it is read for the panel, where a preview on every node of the base would be dead
	 * weight. That is why the block of child articles stood without previews while the same block
	 * inside the portal had them.
	 *
	 * The parent comes from the client and is therefore not trusted, and is not checked either: the
	 * listing is bounded by the knowledge base of the link, so a parent from another base answers with
	 * an empty page - the same answer a parent that does not exist gets. The parent of the rows is the
	 * one that was asked for and not the one stored on them: a child of the hidden main document must
	 * not name it.
	 *
	 * @param array{position?: mixed, id?: mixed}|null $afterCursor keyset cursor of a previous answer,
	 *   handed back verbatim
	 * @return array{documents: array<int, array{id: int, collectionId: int, parentId: int|null,
	 *   title: string, position: int, hasChildren: bool, updatedAt: string, excerpt: string}>,
	 *   nextCursor: array{position: int, id: int}|null}
	 */
	public function getListByParent(
		?int $parentId = null,
		int $limit = self::DEFAULT_LIMIT,
		?array $afterCursor = null,
	): array
	{
		$collectionId = $this->scope->getCollectionId();
		$normalizedParentId = ($parentId !== null && $parentId > 0) ? $parentId : null;
		$normalizedLimit = max(1, min(self::MAX_LIMIT, $limit));
		[$afterPosition, $afterId] = $this->readCursor($afterCursor);

		$rawDocuments = $this->repository->getDocumentMetaByParent(
			$collectionId,
			$normalizedParentId,
			$normalizedLimit + 1,
			$afterPosition,
			$afterId,
		);

		$hasNextPage = count($rawDocuments) > $normalizedLimit;
		$documents = $hasNextPage ? array_slice($rawDocuments, 0, $normalizedLimit) : $rawDocuments;

		$documentIds = array_map(static fn(Document $document): int => (int)$document->getId(), $documents);
		$childrenCountMap = $this->repository->getChildrenCountMapByParentIds($collectionId, $documentIds);
		$cardMeta = $this->cardMetaResolver->resolve(array_fill_keys($documentIds, 0));

		$rows = [];
		$lastPosition = null;
		$lastId = null;
		foreach ($documents as $document)
		{
			$id = (int)$document->getId();
			$lastPosition = (int)$document->getPosition();
			$lastId = $id;

			$rows[] = [
				'id' => $id,
				'collectionId' => $collectionId,
				'parentId' => $normalizedParentId,
				'title' => (string)$document->getTitle(),
				'position' => $lastPosition,
				'hasChildren' => (int)($childrenCountMap[$id] ?? 0) > 0,
				'updatedAt' => $this->observedUpdatedAt($document)->format('c'),
				'excerpt' => (string)($cardMeta[$id]['excerpt'] ?? ''),
			];
		}

		return [
			'documents' => $rows,
			'nextCursor' => ($hasNextPage && $lastId !== null)
				? ['position' => $lastPosition, 'id' => $lastId]
				: null
			,
		];
	}

	/**
	 * The change moment a list shows is the content date, exactly as the ordinary list decides it
	 * (DocumentProvider::observedUpdatedAt): a move, a rename or an archiving must not push the date
	 * a reader sees forward. Legacy rows carry NULL there and fall back to the structural date.
	 */
	private function observedUpdatedAt(Document $document): \Bitrix\Main\Type\DateTime
	{
		return $document->getContentUpdatedAt() ?? $document->getUpdatedAt();
	}

	/**
	 * @return array{0: ?int, 1: ?int}
	 */
	private function readCursor(?array $afterCursor): array
	{
		if (
			!is_array($afterCursor)
			|| !isset($afterCursor['position'], $afterCursor['id'])
			|| (int)$afterCursor['id'] <= 0
		)
		{
			return [null, null];
		}

		return [(int)$afterCursor['position'], (int)$afterCursor['id']];
	}
}
