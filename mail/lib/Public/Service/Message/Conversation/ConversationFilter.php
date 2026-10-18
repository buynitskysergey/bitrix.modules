<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message\Conversation;

use Bitrix\Mail\Helper\Message\Loader\MessageFilter;

/**
 * Raw grid filter of a conversation request.
 *
 * The grid, its counters and the marks of an expanded row read the very same fields through the very
 * same builder, so one search selects one set of messages everywhere it is reported.
 */
final class ConversationFilter
{
	/**
	 * Raw grid filter keys a conversation request understands. Anything else is dropped instead of
	 * being handed to the query builder: the filter arrives from the client.
	 */
	public const SUPPORTED_KEYS = [
		'FILTER_APPLIED',
		'DIR',
		'MD5_DIRS',
		'EXCLUDE_MD5_DIRS',
		'BIND',
		'ATTACHMENTS',
		'IS_SEEN',
		'IS_FAVORITE',
		'LABEL_ID',
		'DATE_from',
		'DATE_to',
		'FIND',
	];

	/** Keys that only describe the folders of the context; a service resolves those itself. */
	public const CONTEXT_KEYS = ['FILTER_APPLIED', 'DIR', 'MD5_DIRS', 'EXCLUDE_MD5_DIRS'];

	/** Fields that belong to the request context or UI preset state but do not narrow the search. */
	public const IGNORED_KEYS = [
		'MAILBOX_IDS',
		'DATE_datesel',
		'DATE_days',
		'DATE_month',
		'DATE_quarter',
		'DATE_year',
	];

	/**
	 * ORM filter of the current search, or null when the request narrows nothing beyond its folders.
	 *
	 * @param string[] $dirMd5List folders the search is evaluated over
	 */
	public static function forSearch(int $mailboxId, int $userId, array $filter, array $dirMd5List): ?array
	{
		$userFields = array_diff_key(
			$filter,
			array_flip(array_merge(self::CONTEXT_KEYS, self::IGNORED_KEYS)),
		);
		$userFields = array_filter($userFields, static fn($value): bool => $value !== '' && $value !== null);
		if ($userFields === [])
		{
			return null;
		}

		$ormFilter = (new MessageFilter([$mailboxId], $userFields, false, $userId))->getArray();
		$ormFilter['@MESSAGE_UID.DIR_MD5'] = $dirMd5List;
		$ormFilter['!=MESSAGE_UID.INTERNALDATE'] = null;

		return $ormFilter;
	}

	/**
	 * Fields of the filter no conversation request understands.
	 *
	 * @return string[]
	 */
	public static function unsupportedKeys(array $filter): array
	{
		return array_values(array_diff(array_keys($filter), self::SUPPORTED_KEYS, self::IGNORED_KEYS));
	}

	/** The filter without the fields no conversation request understands. */
	public static function supportedOnly(array $filter): array
	{
		return array_intersect_key(
			$filter,
			array_flip(array_merge(self::SUPPORTED_KEYS, self::IGNORED_KEYS)),
		);
	}
}
