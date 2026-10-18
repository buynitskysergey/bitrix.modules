<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import;

/**
 * The bases the initiator of a conversion could read when they started it (ALG-03).
 *
 * landing answers about the rights of whoever is asking and about nobody else, so the question is put
 * once - in the request of the initiator, where they are that somebody
 * ({@see \Bitrix\Note\Public\Command\Import\StartImportCommand}) - and the answer travels in the state
 * of the conversion. Every step that reads anything of a base, be it the structure, the content or the
 * access map, checks itself against that answer instead of asking landing again: in a background job
 * landing would answer about whoever's request happens to be driving the agent.
 *
 * The snapshot only ever narrows. It lists what the initiator could read, so a base it does not name
 * is a base they could not read - never the other way round.
 */
final class SourceReadableSnapshot
{
	public const OPTION_KEY = 'sourceReadable';

	// The one source gated by the snapshot: Outline has no access of its own to read, and the local
	// wiki asks its own reader by user id, so neither records one.
	private const RIGHTS_BEARING_SOURCE_TYPE = 'landing';

	/**
	 * Whether the conversion may read the base $sourceCollectionId, by the state of the conversion
	 * alone.
	 */
	public static function allows(array $option, string $sourceCollectionId): bool
	{
		$readable = $option[self::OPTION_KEY] ?? null;
		if (!is_array($readable))
		{
			// A source that records no snapshot is not gated here. landing always records one, so a
			// state of its own without a snapshot is a state nobody vouched for - and it is refused.
			return (string)($option['sourceType'] ?? '') !== self::RIGHTS_BEARING_SOURCE_TYPE;
		}

		return in_array($sourceCollectionId, array_map('strval', $readable), true);
	}

	/**
	 * Whether a conversion of $sourceType carries a snapshot at all.
	 */
	public static function isRecordedFor(string $sourceType): bool
	{
		return $sourceType === self::RIGHTS_BEARING_SOURCE_TYPE;
	}

	/**
	 * The snapshot itself, out of the listing the conversion is started from. Ids are cast because a
	 * base whose code is a number arrives from that listing as an int, while everything downstream
	 * calls a base by a string.
	 *
	 * @param array<int, string|int> $sourceCollectionIds
	 * @return string[]
	 */
	public static function record(array $sourceCollectionIds): array
	{
		return array_values(array_map('strval', $sourceCollectionIds));
	}
}
