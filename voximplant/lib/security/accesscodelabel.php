<?php

namespace Bitrix\Voximplant\Security;

use Bitrix\HumanResources\Integration\UI\EntitySelector\UserGroupProvider;
use Bitrix\HumanResources\Integration\UI\StructureRoleProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Item;

Loc::loadMessages(__FILE__);

/**
 * Resolves human readable names for access codes shown in the telephony permission table.
 *
 * Core name resolver does not know company role codes (AD/AE/AT and their team variants),
 * so they are resolved by the very same entity selector providers that fill the selection dialog.
 */
class AccessCodeLabel
{
	private const COMPANY_ROLE_PATTERN = '/^(AD|AE|AT|ATD|ATE|ATT)0$/';
	private const NODE_ROLE_PATTERN = '/^(AD|AE|AT|ATD|ATE|ATT)[1-9][0-9]*$/';

	/**
	 * @param string[] $accessCodes
	 * @return array<string, array{provider: string|null, name: string}>
	 */
	public static function resolveNames(array $accessCodes): array
	{
		if (empty($accessCodes))
		{
			return [];
		}

		$accessManager = new \CAccess();
		$resolved = $accessManager->GetNames($accessCodes);
		$result = is_array($resolved) ? $resolved : [];

		$rest = array_values(array_diff($accessCodes, array_keys($result)));
		if (empty($rest) || !Loader::includeModule('ui') || !Loader::includeModule('humanresources'))
		{
			return $result;
		}

		$companyRoleCodes = self::filterByPattern($rest, self::COMPANY_ROLE_PATTERN);
		if (!empty($companyRoleCodes))
		{
			$result += self::resolveByProvider(
				new UserGroupProvider(),
				$companyRoleCodes,
				(string)Loc::getMessage('VOXIMPLANT_ACCESS_CODE_PROVIDER_COMPANY_ROLES')
			);
		}

		$nodeRoleCodes = self::filterByPattern($rest, self::NODE_ROLE_PATTERN);
		if (!empty($nodeRoleCodes))
		{
			$result += self::resolveByProvider(
				new StructureRoleProvider(),
				$nodeRoleCodes,
				(string)Loc::getMessage('VOXIMPLANT_ACCESS_CODE_PROVIDER_STRUCTURE_ROLES')
			);
		}

		return $result;
	}

	/**
	 * Provider labels per selection dialog entity, so a row added in the browser reads
	 * the same as the one rendered by resolveNames() after the set is saved.
	 *
	 * @return array<string, string>
	 */
	public static function getEntityProviderNames(): array
	{
		$providerNames = (new \CAccess())->GetProviderNames();

		return [
			// The dialog writes a person as IU{id}, so the label comes from the intranet provider.
			'user' => self::resolveProviderLabel($providerNames, 'intranet', 'IU0'),
			'department' => self::resolveProviderLabel($providerNames, 'intranet', 'D0'),
			'site-groups' => self::resolveProviderLabel($providerNames, 'group', 'G0'),
			'project-access-codes' => self::resolveProviderLabel($providerNames, 'socnetgroup', 'SG0_K'),
			'user-groups' => (string)Loc::getMessage('VOXIMPLANT_ACCESS_CODE_PROVIDER_COMPANY_ROLES'),
			'structure-role' => (string)Loc::getMessage('VOXIMPLANT_ACCESS_CODE_PROVIDER_STRUCTURE_ROLES'),
		];
	}

	/**
	 * A provider may label parts of its own code space separately, and CAccess::GetNames() returns exactly
	 * that per prefix label: the intranet provider has no name of its own, only labels per code shape.
	 * Same rule as BX.Access.GetProviderPrefix, so a sample code of the entity is enough to pick the label.
	 *
	 * @param array<string, array{name: string, prefixes: array}> $providerNames
	 */
	private static function resolveProviderLabel(array $providerNames, string $providerId, string $sampleCode): string
	{
		$provider = $providerNames[$providerId] ?? null;
		if (!is_array($provider))
		{
			return '';
		}

		foreach ((array)($provider['prefixes'] ?? []) as $prefix)
		{
			$pattern = (string)($prefix['pattern'] ?? '');
			if ($pattern !== '' && preg_match('/' . $pattern . '/', $sampleCode) === 1)
			{
				return (string)($prefix['prefix'] ?? '');
			}
		}

		return (string)($provider['name'] ?? '');
	}

	/**
	 * @param string[] $accessCodes
	 * @return string[]
	 */
	private static function filterByPattern(array $accessCodes, string $pattern): array
	{
		return array_values(array_filter(
			$accessCodes,
			static fn($accessCode) => preg_match($pattern, (string)$accessCode) === 1
		));
	}

	/**
	 * @param string[] $accessCodes
	 * @return array<string, array{provider: string, name: string}>
	 */
	private static function resolveByProvider(BaseProvider $provider, array $accessCodes, string $providerName): array
	{
		if (!$provider->isAvailable())
		{
			return [];
		}

		$result = [];
		/** @var Item $item */
		foreach ($provider->getItems($accessCodes) as $item)
		{
			$result[(string)$item->getId()] = [
				'provider' => $providerName,
				'name' => $item->getTitle(),
			];
		}

		return $result;
	}
}
