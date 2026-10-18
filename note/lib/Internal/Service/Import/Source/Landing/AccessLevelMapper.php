<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Source\Landing;

use Bitrix\Note\Internal\Access\Service\CollectionAccessService;

/**
 * The whole of DTO-01: which access level of the converted knowledge base a landing right of its
 * site carries. {@see \Bitrix\Note\Internal\Service\Import\Source\LandingSource} asks for the
 * level of a subject and never works one out itself, so every rule below is stated exactly once.
 *
 * | landing right      | named subject (`U...`, `G...`, `SG...`, `DR...`) | global subject (`*`, `AU`, `G2`) |
 * | `read`             | view                                     | view                             |
 * | `edit`             | manage                                   | view                             |
 * | `sett`             | manage                                   | view                             |
 * | anything else/none | no level, the subject is left out        | no level, no policy is set       |
 *
 * Two rules shape that table, and neither is a matter of taste - a right of landing does not name
 * the same set of people a grant of note does:
 *   - the `moderate` level is never derived from a right of the source. The site-setting right
 *     belongs to every knowledge-base administrator of the portal
 *     ({@see \Bitrix\Note\Internal\Integration\Landing\RightsReader} adds it for each of them),
 *     so a moderator taken from it would hand all of them the ACL of the converted
 *     base. Moderation goes to the initiator of the conversion alone, granted by
 *     {@see \Bitrix\Note\Internal\Service\Import\Step\CreateCollectionStep} and
 *     {@see \Bitrix\Note\Internal\Service\Import\CollectionAccessTransfer};
 *   - a global subject stands for the whole portal, so its right never rises above viewing: a site
 *     open to all authenticated for editing would otherwise make every employee an editor of the
 *     base. A global subject carries no row of the permission list at all - it folds into the single
 *     policy level of the collection ({@see isGlobalSubject}).
 *
 * A subject holding several rights takes the highest level of them, by note's own ranking of levels
 * ({@see maxLevel}).
 */
final class AccessLevelMapper
{
	private const LEVEL_BY_RIGHT = [
		'read' => CollectionAccessService::LEVEL_CODE_VIEW,
		'edit' => CollectionAccessService::LEVEL_CODE_MANAGE,
		'sett' => CollectionAccessService::LEVEL_CODE_MANAGE,
	];

	private const GLOBAL_SUBJECT_CODES = ['*', 'AU', 'G2'];

	private const GLOBAL_SUBJECT_MAX_LEVEL = CollectionAccessService::LEVEL_CODE_VIEW;

	private const LEVEL_RANK = [
		CollectionAccessService::LEVEL_CODE_NONE => 0,
		CollectionAccessService::LEVEL_CODE_VIEW => 10,
		CollectionAccessService::LEVEL_CODE_MANAGE => 30,
		CollectionAccessService::LEVEL_CODE_MODERATE => 40,
	];

	/**
	 * Level the subject gets on the converted collection, or null when its rights carry none - such
	 * a subject enters neither the permission list nor the policy of the collection.
	 *
	 * @param string[] $rights landing rights of the subject
	 *        ({@see \Bitrix\Note\Internal\Integration\Landing\RightsReader::getSiteAccessTypes})
	 */
	public static function levelFor(string $subjectCode, array $rights): ?string
	{
		$level = self::levelForRights($rights);
		if ($level === null)
		{
			return null;
		}

		return self::isGlobalSubject($subjectCode)
			? self::minLevel($level, self::GLOBAL_SUBJECT_MAX_LEVEL)
			: $level
		;
	}

	/**
	 * Whether the code stands for everyone on the portal rather than for one subject: such a code
	 * becomes the policy level of the collection instead of a row of its permission list.
	 */
	public static function isGlobalSubject(string $subjectCode): bool
	{
		return in_array($subjectCode, self::GLOBAL_SUBJECT_CODES, true);
	}

	/**
	 * The higher of two levels, with null for "no level so far": how the rights of one subject and
	 * the policies of all the global ones are folded together.
	 */
	public static function maxLevel(?string $left, ?string $right): ?string
	{
		if ($left === null || $right === null)
		{
			return $left ?? $right;
		}

		return self::rank($right) > self::rank($left) ? $right : $left;
	}

	/**
	 * @param string[] $rights
	 */
	private static function levelForRights(array $rights): ?string
	{
		$level = null;
		foreach ($rights as $right)
		{
			$level = self::maxLevel($level, self::LEVEL_BY_RIGHT[$right] ?? null);
		}

		return $level;
	}

	private static function minLevel(string $left, string $right): string
	{
		return self::rank($right) < self::rank($left) ? $right : $left;
	}

	private static function rank(string $level): int
	{
		return self::LEVEL_RANK[$level] ?? 0;
	}
}
