<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\EntityAddressType;
use Bitrix\Main\Localization\Loc;

final class AddressTypeTitleResolver
{
	private const MESSAGE_CODE_BY_NAME = [
		EntityAddressType::PrimaryName => 'CRM_ADDRESS_TYPE_PRIMARY',
		EntityAddressType::SecondaryName => 'CRM_ADDRESS_TYPE_SECONDARY',
		EntityAddressType::ThirdName => 'CRM_ADDRESS_TYPE_THIRD',
		EntityAddressType::HomeName => 'CRM_ADDRESS_TYPE_HOME',
		EntityAddressType::WorkName => 'CRM_ADDRESS_TYPE_WORK',
		EntityAddressType::RegisteredName => 'CRM_ADDRESS_TYPE_REGISTERED',
		EntityAddressType::CustomName => 'CRM_ADDRESS_TYPE_CUSTOM',
		EntityAddressType::PostName => 'CRM_ADDRESS_TYPE_POST',
		EntityAddressType::BeneficiaryName => 'CRM_ADDRESS_TYPE_BENEFICIARY',
		EntityAddressType::BankName => 'CRM_ADDRESS_TYPE_BANK',
		EntityAddressType::DeliveryName => 'CRM_ADDRESS_TYPE_DELIVERY',
		EntityAddressType::BillingName => 'CRM_ADDRESS_TYPE_BILLING',
	];

	public function resolve(
		string $name,
		string $responseLanguageId,
		string $portalLanguageId,
	): string
	{
		$messageCode = self::MESSAGE_CODE_BY_NAME[$name] ?? null;
		if ($messageCode === null)
		{
			return $name;
		}

		$languageIds = array_unique([$responseLanguageId, $portalLanguageId, 'en']);

		foreach ($languageIds as $languageId)
		{
			$title = $this->resolveForLanguage($messageCode, $languageId);
			if ($title !== null && trim($title) !== '')
			{
				return $title;
			}
		}

		return $name;
	}

	private function resolveForLanguage(string $messageCode, string $languageId): ?string
	{
		if (preg_match('/^[a-z]{2}$/iD', $languageId) !== 1)
		{
			return null;
		}

		$messages = Loc::loadLanguageFile($this->getSourceFile(), $languageId);
		$title = $messages[$messageCode] ?? null;

		return is_string($title) ? $title : null;
	}

	private function getSourceFile(): string
	{
		return (new \ReflectionClass(EntityAddressType::class))->getFileName();
	}
}
