<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Exception\Validation\ValidationException;
use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * The upper bound of a list a request carries, and the single place it is stated.
 *
 * A list inside a request - the rows of a replacement, the identifiers of a filter - costs the portal what
 * its length says: every element is read, normalized and compared, and paging bounds the answer rather than
 * the question. Without a bound the cost of a single call is the client's to choose.
 *
 * The number was chosen as the largest page REST v3 will ever answer ({@see PaginationStructure::MAX_LIMIT}
 * at the time of writing): a request naming more elements than one answer can hold is a request no method
 * of the API serves, and one number is one number to remember. It is stated here rather than borrowed,
 * because how long a question may be and how long an answer may be are two decisions, and the paging of
 * another module is free to change its own without moving this one. Nothing is silently cut off - a list
 * over the bound is refused with the address of the property it came in, because a request half-carried out
 * is the answer hardest to notice.
 */
final class RequestListLimit
{
	public const MAX_LENGTH = 1000;

	/**
	 * @param array<mixed> $values the list as the request carries it.
	 * @param string $field where it came in, in the notation of the answer: `items`, `filter.id`.
	 * @param class-string<ValidationException> $refusal how the property that carries the list answers its
	 *        refusals. A filter answers as a filter and the body of a request as the body: a client that
	 *        branches on the code of an answer must not be told that a filter it sent is a malformed body
	 *        merely because the refusal happened to be about length.
	 * @throws ValidationException of the class the caller named.
	 */
	public static function check(array $values, string $field, string $refusal): void
	{
		if (count($values) <= self::MAX_LENGTH)
		{
			return;
		}

		throw new $refusal([
			new Error(
				new LocalizableMessage(
					'CRM_V2_REST_REQUEST_LIST_TOO_LONG',
					['#FIELD#' => $field, '#LIMIT#' => (string)self::MAX_LENGTH],
				),
				$field,
			),
		]);
	}
}
