<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Exceptions;

/**
 * The collection holds more documents than one answer is allowed to carry.
 *
 * Thrown instead of a cut tree: a caller holding half a collection cannot tell which half it is
 * missing, and has nothing to read the rest with.
 */
final class CollectionTreeTooLargeException extends \RuntimeException
{
	public function __construct(private readonly int $budget)
	{
		parent::__construct('The tree of the collection is over the budget of ' . $budget . ' nodes');
	}

	public function getBudget(): int
	{
		return $this->budget;
	}
}
