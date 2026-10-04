<?php

namespace Bitrix\Voximplant\Security;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Voximplant\Model\RoleAccessTable;

/**
 * Decides which access codes may be written into the role access table.
 */
class AccessCodeFilter
{
	private const LOGGER_ID = 'voximplant.security.accessCodeFilter';

	/** @var array<string, true> */
	private array $storedAccessCodes;

	/**
	 * @param array<string, true> $storedAccessCodes Codes accepted as is, as a lookup map.
	 */
	private function __construct(array $storedAccessCodes)
	{
		$this->storedAccessCodes = $storedAccessCodes;
	}

	/**
	 * Filter for the set stored right now: it has to be created before the set is rewritten,
	 * otherwise there is nothing left to compare the incoming codes with.
	 */
	public static function createForCurrentSet(): self
	{
		return new self(self::loadStoredAccessCodes());
	}

	/**
	 * @param array<string, true> $storedAccessCodes Codes accepted as is, as a lookup map.
	 */
	public static function createForStoredCodes(array $storedAccessCodes): self
	{
		return new self($storedAccessCodes);
	}

	/**
	 * A code that was already stored is accepted as is: a permission configured earlier must not disappear
	 * only because its code is older than the current code registry of the kernel. New codes are validated,
	 * and a rejected one is logged - silent rejection leaves no way to diagnose it on a portal.
	 */
	public function isAcceptable(string $accessCode): bool
	{
		if ($accessCode === '')
		{
			return false;
		}

		if (isset($this->storedAccessCodes[$accessCode]) || AccessCode::isValid($accessCode))
		{
			return true;
		}

		$logger = (new LoggerFactory())->createById(self::LOGGER_ID);
		$logger?->warning('Rejected unknown access code {code}', ['code' => $accessCode]);

		return false;
	}

	/**
	 * @return array<string, true>
	 */
	private static function loadStoredAccessCodes(): array
	{
		$result = [];
		$cursor = RoleAccessTable::getList(['select' => ['ACCESS_CODE']]);
		while ($row = $cursor->fetch())
		{
			$result[(string)$row['ACCESS_CODE']] = true;
		}

		return $result;
	}
}
