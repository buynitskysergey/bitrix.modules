<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Exceptions;

/**
 * The knowledge base is closed for writing while archived. Until now the ban lived on the
 * archivedness of the document alone, which held only while there was no way in: a new document
 * is created live, so a creation inside an archived base would leave it archived by flag only.
 */
final class CollectionNotWritableException extends \RuntimeException
{
	public function __construct(private readonly int $collectionId)
	{
		parent::__construct('The knowledge base is read-only in its current state');
	}

	public function getCollectionId(): int
	{
		return $this->collectionId;
	}
}
