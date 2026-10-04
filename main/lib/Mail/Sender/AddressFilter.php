<?php

declare(strict_types=1);

namespace Bitrix\Main\Mail\Sender;

use Bitrix\Main\Mail\Address;
use Bitrix\Main\ORM\Query\Query;

/**
 * Owns the condition that looks a sender up by address. The stored address is normalized on write and
 * the rows accumulated before that are converted in the background, so the lookup is one and the same
 * everywhere: an exact comparison over the indexed column. A consumer receives the applied condition
 * and builds none of it itself.
 */
class AddressFilter
{
	/**
	 * Applies the address condition to a query over the sender entity. Addresses may come in any case;
	 * anything in the list that is not an address is dropped rather than raised on, because the list is
	 * assembled by the caller and this method promises no exceptions.
	 *
	 * @param array<mixed> $emails
	 */
	public static function applyEmails(Query $query, array $emails): Query
	{
		$emails = self::normalizeEmails($emails);

		if ($emails === [])
		{
			// a query left without a condition would hand out every sender of the portal
			return $query->whereExpr('1 = 0', []);
		}

		return $query->whereIn('EMAIL', $emails);
	}

	/**
	 * @param array<mixed> $emails
	 * @return string[]
	 */
	private static function normalizeEmails(array $emails): array
	{
		$normalized = [];
		foreach ($emails as $email)
		{
			// a non-scalar value is not an address, and the filter must not raise on it
			$email = is_scalar($email) ? Address::normalizeEmail((string)$email) : '';
			if ($email !== '')
			{
				$normalized[] = $email;
			}
		}

		return array_values(array_unique($normalized));
	}
}
