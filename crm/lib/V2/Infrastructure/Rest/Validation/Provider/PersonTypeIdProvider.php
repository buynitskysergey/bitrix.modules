<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

/**
 * @internal
 */
class PersonTypeIdProvider implements ValidValuesProviderInterface
{
	public const CONTACT = 'CONTACT';
	public const COMPANY = 'COMPANY';

	public static function getValidValues(Context $context): array
	{
		$personTypeIds = static::getPersonTypeIds();

		return array_values(array_filter(
			[self::CONTACT, self::COMPANY],
			static fn(string $code): bool => !empty($personTypeIds[$code]),
		));
	}

	public static function getPersonTypeId(?string $code): ?int
	{
		if (!in_array($code, [self::CONTACT, self::COMPANY], true))
		{
			return null;
		}

		$id = static::getPersonTypeIds()[$code] ?? null;

		return !empty($id) ? (int)$id : null;
	}

	public static function getPersonTypeCode(?int $id): ?string
	{
		if ($id === null)
		{
			return null;
		}

		foreach (static::getPersonTypeIds() as $code => $personTypeId)
		{
			if ((int)$personTypeId === $id)
			{
				return (string)$code;
			}
		}

		return null;
	}

	/** @return array<string, int|string> */
	protected static function getPersonTypeIds(): array
	{
		return \CCrmPaySystem::getPersonTypeIDs();
	}
}
