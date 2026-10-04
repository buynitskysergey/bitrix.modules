<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Connector;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Service\License\LicenseService;

class Mobile
{
	private const MENU_ITEM_ID = 'note';
	private const MENU_ITEM_SORT = 800;
	private const TARGET_SECTION_CODE = 'teamwork';
	private const ENTRY_URL = '/mobile/note/';
	private const ICON_NAME = 'library_base';
	private const ICON_COLOR = '#7a7adc';

	public static function onMobileMenuStructureBuilt($menu): array
	{
		if (!is_array($menu))
		{
			return $menu;
		}

		if (!Loader::includeModule('mobileapp') || !Loader::includeModule('mobile'))
		{
			return $menu;
		}

		if (!self::isAccessibleForCurrentUser())
		{
			return $menu;
		}

		if (!self::isToolEnabled())
		{
			return $menu;
		}

		$sectionIndex = self::findSectionIndex($menu, self::TARGET_SECTION_CODE);
		if ($sectionIndex === null)
		{
			return $menu;
		}

		if (!isset($menu[$sectionIndex]['items']) || !is_array($menu[$sectionIndex]['items']))
		{
			$menu[$sectionIndex]['items'] = [];
		}

		$menu[$sectionIndex]['items'][] = self::buildMenuItem();

		return $menu;
	}

	private static function isAccessibleForCurrentUser(): bool
	{
		try
		{
			return AccessController::getCurrent()
				->check(ActionDictionary::ACTION_NOTE_ACCESS)
			;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	private static function isToolEnabled(): bool
	{
		// Delegates to the single tariff/tool source of truth (behavior-identical).
		return (new LicenseService())->isToolEnabled();
	}

	private static function findSectionIndex(array $menu, string $code): ?int
	{
		foreach ($menu as $idx => $section)
		{
			if (is_array($section) && (($section['code'] ?? null) === $code))
			{
				return $idx;
			}
		}

		return null;
	}

	private static function buildMenuItem(): array
	{
		return [
			'id' => self::MENU_ITEM_ID,
			'title' => Loc::getMessage('NOTE_CONNECTOR_MB_MENU_TITLE'),
			'sort' => self::MENU_ITEM_SORT,
			'imageName' => self::ICON_NAME,
			'color' => self::ICON_COLOR,
			'tag' => 'new',
			'params' => [
				'url' => self::ENTRY_URL,
				'cache' => false,
				'useSearchBar' => false,
			],
		];
	}
}
