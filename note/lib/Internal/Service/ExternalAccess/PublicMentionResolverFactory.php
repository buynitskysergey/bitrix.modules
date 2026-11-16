<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Service\ExternalAccess\Mention\PublicDocumentMentionResolver;
use Bitrix\Note\Internal\Service\ExternalAccess\Mention\UnavailableMentionResolver;
use Bitrix\Note\Internal\Service\Mention\MentionEntityResolver;

/**
 * The set of mention resolvers of the public circuit.
 *
 * Every case of MentionType is listed, and that is the point of the factory: MentionResolver falls
 * back to its production resolver for a type the injected map does not cover, so a type forgotten
 * here would silently reach UserMentionResolver - the very path the public circuit must never take.
 * A new mention type added to the enum breaks the match below instead of leaking through it.
 */
final class PublicMentionResolverFactory
{
	/**
	 * @return array<string, MentionEntityResolver>
	 */
	public function createResolvers(int $collectionId): array
	{
		$resolvers = [];
		foreach (MentionType::cases() as $case)
		{
			$resolvers[$case->value] = match ($case) {
				MentionType::Document => new PublicDocumentMentionResolver($collectionId),
				MentionType::User, MentionType::Collection, MentionType::Task, MentionType::DiskFolder
					=> new UnavailableMentionResolver($case->value),
			};
		}

		return $resolvers;
	}
}
