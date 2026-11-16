<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Util;

use Bitrix\Main\Type\DateTime;

/**
 * The date half of a keyset cursor, as it comes back from the client.
 *
 * A cursor travels to the browser and returns from it, so its date is client input like any other -
 * and the date constructor answers unparsable input with an exception, not with a null. Left alone
 * that turns a listing action into an error response with the exception text in it. Here an
 * unreadable date is simply no date: the caller then behaves as if no cursor was given at all and
 * answers with the first page.
 */
final class CursorDate
{
	public static function parse(mixed $raw): ?DateTime
	{
		if (!is_string($raw) || $raw === '')
		{
			return null;
		}

		try
		{
			return DateTime::createFromPhp(new \DateTime($raw));
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
