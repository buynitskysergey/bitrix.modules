<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Read;

use Bitrix\Note\Infrastructure\Rest\V3\Access\EntityState;

/**
 * The form of a request to list documents: which knowledge base, which states, which fields, where
 * the previous page stopped.
 *
 * The order is not carried here and cannot be: the answer is assembled from three streams of
 * visibility, and each of them is ordered by the keyset its own index gives away
 * ({@see DocumentListReader}).
 */
final class DocumentListQuery
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;

	/**
	 * Fields the list does not answer with at all - not by default, and not on request either.
	 *
	 * The text of a document is the heaviest column of the table, and a page multiplies that bill by
	 * the page: a full page of two hundred documents is hundreds of megabytes of markdown read,
	 * serialized and sent so that a client can print a list of titles - a promise that breaks as a
	 * timeout or a truncated body rather than as an answer. The rule of the module is that the text
	 * of a document is answered when that document is read, and nowhere else, so a select that names
	 * one of these is refused by {@see \Bitrix\Note\Infrastructure\Rest\V3\Request\ListDocumentsRequest::getSelect()}
	 * instead of being quietly dropped: a page silently short of the field reads as a page of empty
	 * documents.
	 */
	public const HEAVY_FIELDS = ['markdown'];

	/** @var EntityState[] */
	public readonly array $states;

	/** @var string[] */
	public readonly array $select;

	public readonly int $limit;

	/**
	 * @param int|null $collectionId null asks about every document the caller reaches, a positive id
	 *                               about one knowledge base, 0 about the documents that have none
	 * @param EntityState[] $states empty is read as the live ones alone
	 * @param string[] $select names of DTO fields, never one of {@see self::HEAVY_FIELDS}; an empty
	 *                         list means the whole answer, which is the same set
	 */
	public function __construct(
		public readonly ?int $collectionId = null,
		array $states = [],
		array $select = [],
		int $limit = 0,
		public readonly ?DocumentListCursor $cursor = null,
	)
	{
		$this->states = $states === [] ? [EntityState::Live] : $states;
		$this->select = $select;
		$this->limit = $limit > 0 ? min($limit, self::MAX_LIMIT) : self::DEFAULT_LIMIT;
	}

	public function wants(EntityState $state): bool
	{
		return in_array($state, $this->states, true);
	}

	public function wantsOnly(EntityState $state): bool
	{
		return $this->states === [$state];
	}
}
