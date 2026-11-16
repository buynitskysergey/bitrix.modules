<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller;

use Bitrix\Note\Internal\Service\ExternalAccess\PublicScope;

/**
 * The link a public request came in by, as decided by the pre-filter.
 *
 * The context is never a parameter of an action and never comes from the request: it is built by
 * the filter after the token has been resolved, and handed to the controller by a typed setter. A
 * path along which the context could be substituted from the outside would be the whole isolation
 * of the feature undone.
 *
 * The services of the domain take the knowledge base of the link as {@see PublicScope} and this is
 * the class that brings it to them: the answer is the same, only the direction of the dependency
 * differs - the domain declares what it needs and does not reach up here to find it.
 */
final class ExternalAccessContext implements PublicScope
{
	/**
	 * @param array|null $fileLink the document-to-file row the filter already walked through to
	 *                             reach this link, when it started from a file. It is a shortcut and
	 *                             never a right: what may be read is decided by the three fields
	 *                             above, and an action that does not recognise the row reads its own.
	 */
	public function __construct(
		private readonly int $linkRecordId,
		private readonly int $collectionId,
		private readonly int $accessLevel,
		private readonly ?array $fileLink = null,
	) {}

	/**
	 * @return array|null the row of DocumentFileLinkRepository, or null when the request named no
	 *                    file to begin with
	 */
	public function getFileLink(): ?array
	{
		return $this->fileLink;
	}

	/**
	 * The id of the link record. Stable across token rotation, which is why the channel tag of live
	 * updates is built on it and not on the token.
	 */
	public function getLinkRecordId(): int
	{
		return $this->linkRecordId;
	}

	public function getCollectionId(): int
	{
		return $this->collectionId;
	}

	public function getAccessLevel(): int
	{
		return $this->accessLevel;
	}
}
