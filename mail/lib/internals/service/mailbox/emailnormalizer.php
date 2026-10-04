<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals\Service\Mailbox;

use Bitrix\Main\Mail\Address;

final class EmailNormalizer
{
	private const MAILBOX_ADDRESS_FIELDS = ['EMAIL', 'NAME', 'LOGIN'];

	public function normalize(string $email): ?string
	{
		$address = new Address(trim($email));
		if (!$address->validate())
		{
			return null;
		}

		return mb_strtolower($address->getEmail());
	}

	public function normalizeMailbox(array $mailbox): ?string
	{
		foreach (self::MAILBOX_ADDRESS_FIELDS as $fieldName)
		{
			$normalizedEmail = $this->normalize((string)($mailbox[$fieldName] ?? ''));
			if ($normalizedEmail !== null)
			{
				return $normalizedEmail;
			}
		}

		return null;
	}
}
