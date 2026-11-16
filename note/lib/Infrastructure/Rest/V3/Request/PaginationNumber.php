<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * A number read out of `pagination`, which arrives as the body wrote it.
 *
 * Pagination is deliberately not run through a Structure - a cursor is opaque and has nothing to
 * validate against a DTO - so nothing on the way in says its fields are numbers. A cast alone does
 * not either: it turns an array into 1 and `"9abc"` into 9, and answers with a page the client
 * never asked for while telling them nothing. Refusing is the only honest reading, and the answer
 * is the same one an unknown cursor stream already gets.
 *
 * Lives in one place because the rule was written out three times - the cursor of the document
 * list, and the limit of each of the two list requests - and a rule spelled out per caller is the
 * rule that drifts.
 */
final class PaginationNumber
{
	/**
	 * @param string $field name of the field as the client wrote it, for the error
	 * @return int 0 when the field was not sent at all
	 * @throws RequestValidationException when the value is anything but a number
	 */
	public static function read(mixed $value, string $field): int
	{
		if ($value === null)
		{
			return 0;
		}

		if (!is_numeric($value))
		{
			throw new RequestValidationException([
				new Error('must be a number', $field),
			]);
		}

		return (int)$value;
	}
}
