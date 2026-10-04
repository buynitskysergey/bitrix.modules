<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Localization\Translation;
use Bitrix\Main\SiteTable;

final class EntityTypeTitleProvider
{
	private const MESSAGE_CODE_BY_ENTITY_TYPE_ID = [
		OwnerType::LEAD => 'CRM_OWNER_TYPE_LEAD',
		OwnerType::DEAL => 'CRM_OWNER_TYPE_DEAL',
		OwnerType::CONTACT => 'CRM_OWNER_TYPE_CONTACT',
		OwnerType::COMPANY => 'CRM_OWNER_TYPE_COMPANY',
		OwnerType::QUOTE => 'CRM_OWNER_TYPE_QUOTE_MSGVER_1',
		OwnerType::SMART_INVOICE => 'CRM_OWNER_TYPE_INVOICE',
		OwnerType::SMART_DOCUMENT => 'CRM_OWNER_TYPE_SMART_DOCUMENT',
		OwnerType::SMART_B2E_DOCUMENT => 'CRM_OWNER_TYPE_SMART_B2E_DOC',
	];

	private const DEFAULT_LANGUAGE_ID = 'en';

	public function getTitle(EntityType $entityType, string $responseLanguage): string
	{
		$title = $this->getLocalizedDescription($entityType, $responseLanguage);
		if ($title !== '')
		{
			return $title;
		}

		$portalLanguage = $this->getPortalLanguage();
		if ($portalLanguage === $responseLanguage)
		{
			return '';
		}

		return $this->getLocalizedDescription($entityType, $portalLanguage);
	}

	private function getLocalizedDescription(EntityType $entityType, string $language): string
	{
		if (!preg_match('/^[a-z]{2}$/i', $language))
		{
			return '';
		}

		$languageFile = Translation::convertLangPath($this->getLanguageFile($language), $language);
		if (!is_file($languageFile))
		{
			return '';
		}

		$messageCode = self::MESSAGE_CODE_BY_ENTITY_TYPE_ID[$entityType->getId()] ?? null;
		if ($messageCode === null)
		{
			return '';
		}

		$messages = (static function (string $languageFile): array {
			$MESS = [];
			include $languageFile;

			return $MESS;
		})($languageFile);

		return (string)($messages[$messageCode] ?? '');
	}

	private function getPortalLanguage(): string
	{
		$language = SiteTable::getDefaultLanguageId();
		if (is_string($language) && $language !== '')
		{
			return $language;
		}

		return defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : self::DEFAULT_LANGUAGE_ID;
	}

	private function getLanguageFile(string $language): string
	{
		return dirname(__DIR__, 5) . "/lang/{$language}/classes/general/crm_owner_type.php";
	}
}
