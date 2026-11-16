<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Note\Internal\Model\Collection;

/**
 * A public link that has passed every question of the resolver, with the knowledge base it opens.
 *
 * The object exists only as the answer of PublicLinkResolver and is never assembled from request
 * data: the scope of a link is defined by resolving its token and by nothing else.
 */
final class ResolvedPublicLink
{
	public function __construct(
		public readonly int $linkRecordId,
		public readonly int $collectionId,
		public readonly int $accessLevel,
		public readonly Collection $collection,
		/**
		 * The token of the record as of the read that authorized this request. Carried out of here
		 * so that a caller binding something to the link - the context mark of a visitor - binds it
		 * to the token this resolve passed against, instead of asking the record a second time and
		 * getting whatever a reissue has put there in between.
		 */
		public readonly string $token,
	) {}
}
