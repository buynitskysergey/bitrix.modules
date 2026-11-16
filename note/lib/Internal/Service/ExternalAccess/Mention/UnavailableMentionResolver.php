<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess\Mention;

use Bitrix\Note\Internal\Service\Mention\MentionEntityResolver;

/**
 * The answer of the public circuit for every kind of mention that leads inside the portal:
 * employees, knowledge bases and tasks.
 *
 * It is a resolver and not a gap in the map on purpose. A type left unresolved falls back to the
 * production resolver of MentionResolver, and for an employee that resolver hands out a name, an
 * avatar and the address of a profile without a single rights check - the one thing the public
 * circuit must never reach. Answering "unavailable" is also the honest answer: the employee card,
 * the knowledge base and the task all live behind the authentication a visitor does not have, so
 * there is nothing to show even when the entity exists.
 */
final class UnavailableMentionResolver implements MentionEntityResolver
{
	public function __construct(private readonly string $type) {}

	public function resolve(array $ids): array
	{
		$resolved = [];
		foreach ($ids as $rawId)
		{
			$id = (int)$rawId;
			if ($id > 0)
			{
				$resolved[$id] = PublicMentionReason::unavailable($this->type, $id);
			}
		}

		return $resolved;
	}
}
