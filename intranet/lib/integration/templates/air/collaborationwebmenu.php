<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Templates\Air;

use Bitrix\Intranet\Internal\Integration;
use Bitrix\Intranet\Site\Sections\CollaborationSection;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\CurrentUser;

class CollaborationWebMenu
{
	public static function getMenuId(string $context): ?string
	{
		if (
			!self::isContextAvailable($context)
			|| !self::isEnabled()
			|| !Integration\Im\CollaborationMenu::isAvailable()
		)
		{
			return null;
		}

		return self::getLegacyMenuId(SITE_DIR) . '_v2';
	}

	public static function getMenu(string $context): ?array
	{
		if (!self::isContextAvailable($context))
		{
			return null;
		}

		$chatItems = self::getAvailableChatItems();
		if ($chatItems === [])
		{
			return null;
		}

		$sections = [
			'messenger' => CollaborationSection::getMessenger(false),
			'sync' => CollaborationSection::getSync(),
			'calendar' => CollaborationSection::getCalendar(true),
			'mail' => CollaborationSection::getMail(),
			'notification' => CollaborationSection::getNotifications(),
			'boards' => CollaborationSection::getBoards(),
			'documents' => CollaborationSection::getOnlineDocs(),
			'feed' => CollaborationSection::getFeed(),
			'disk' => CollaborationSection::getDisk(),
		];
		$counters = \CUserCounter::GetValues(CurrentUser::get()->getId());
		$legacyId = self::getLegacyMenuId(SITE_DIR);

		return [
			'ID' => $legacyId . '_v2',
			'LEGACY_ID' => $legacyId,
			'CLIENT_CONFIG' => [
				'INTRANET_COLLABORATION_MENU_ID' => $legacyId . '_v2',
				'INTRANET_COLLABORATION_MENU_LEGACY_ID' => $legacyId,
			],
			'ITEMS' => self::compose($sections, $chatItems, $counters, $context === 'messenger'),
		];
	}

	public static function getLegacyMenuId(string $siteDir): string
	{
		return 'top_menu_id_' . str_replace('/', '_', trim($siteDir . 'collaboration/', '/'));
	}

	public static function compose(array $sections, array $chatItems, array $counters, bool $isMessenger): array
	{
		$chatItems = array_column($chatItems, null, 'ID');
		$items = [];
		foreach (['messenger', 'sync', 'calendar', 'mail', 'notification', 'boards', 'documents', 'feed', 'disk'] as $id)
		{
			$section = $sections[$id] ?? [];
			if (!($section['available'] ?? false))
			{
				continue;
			}

			$item = self::convertSection($section);
			if (in_array($id, ['messenger', 'notification'], true))
			{
				if (!isset($chatItems[$id]))
				{
					continue;
				}

				$item = array_replace($item, $chatItems[$id], ['ID' => $item['ID']]);
				unset($item['COUNTER']);
				if ($id === 'messenger')
				{
					if (($item['TEXT'] ?? '') === '' && ($section['title'] ?? '') !== '')
					{
						$item['TEXT'] = $section['title'];
					}

					$item['COUNTER_ID'] = 'im-message';
					if ($isMessenger)
					{
						$item['URL'] = '';
					}
					else
					{
						$item['ON_CLICK'] = '';
					}
				}
			}

			if (in_array($id, ['feed', 'disk'], true))
			{
				$item['IS_DISABLED'] = true;
			}
			$items[] = $item;
		}

		$items = self::applyCounters($items, $counters);
		if (isset($chatItems['settings']))
		{
			$items[] = array_replace($chatItems['settings'], ['IS_DISABLED' => true]);
		}

		return $items;
	}

	public static function prepareLeftMenuItems(
		array $items,
		string $menuId,
		string $entryUrl,
		string $siteDir,
	): array
	{
		$descendantIds = ['menu_teamwork' => true];
		$descendantCounterIds = [];
		$descendantLinks = [];
		$collaborationItemIndex = null;
		$result = [];
		foreach ($items as $item)
		{
			if (isset($descendantIds[$item['GROUP_ID'] ?? '']))
			{
				$descendantIds[$item['ID']] = true;
				if (in_array(($item['ITEM_TYPE'] ?? ''), ['custom', 'self', 'standard', 'admin'], true))
				{
					unset($item['GROUP_ID']);
					$item['PARAMS']['parent_id'] = null;
					$result[] = $item;

					continue;
				}

				if (($item['PARAMS']['counter_id'] ?? '') !== '')
				{
					$descendantCounterIds[] = $item['PARAMS']['counter_id'];
				}
				foreach ($item['ADDITIONAL_LINKS'] ?? [] as $link)
				{
					if (is_string($link))
					{
						$descendantLinks[] = $link;
					}
				}

				if (isset($item['PARAMS']['real_link']) && is_string($item['LINK'] ?? null))
				{
					$descendantLinks[] = $item['LINK'];
				}
				$currentLink = is_string($item['PARAMS']['real_link'] ?? null)
					? $item['PARAMS']['real_link']
					: (is_string($item['LINK'] ?? null) ? $item['LINK'] : '')
				;
				if (preg_match('~^' . $siteDir . 'index\\.php~i', $currentLink))
				{
					$currentLink = $siteDir;
				}
				elseif (!empty($item['PARAMS']['onclick']))
				{
					$currentLink = '';
				}
				if ($currentLink !== '')
				{
					$descendantLinks[] = $currentLink;
				}

				continue;
			}

			if ($item['ID'] === 'menu_teamwork')
			{
				unset($item['IS_GROUP'], $item['PARAMS']['collapse_mode']);
				$item['ITEM_TYPE'] = 'default';
				$item['LINK'] = $entryUrl;
				$item['PARAMS']['is_collaboration_menu'] = true;
				$item['PARAMS']['collaboration_entry_url'] = $entryUrl;
				$item['PARAMS']['top_menu_id'] = $menuId;
				$item['PARAMS']['can_be_first_item'] = false;
				$collaborationItemIndex = count($result);
			}
			$result[] = $item;
		}
		if ($collaborationItemIndex !== null)
		{
			$result[$collaborationItemIndex]['PARAMS']['aggregate_counter_ids'] = array_values(
				array_unique($descendantCounterIds),
			);
			$result[$collaborationItemIndex]['ADDITIONAL_LINKS'] = array_values(
				array_unique(array_merge(
					$result[$collaborationItemIndex]['ADDITIONAL_LINKS'] ?? [],
					$descendantLinks,
				)),
			);
		}

		return $result;
	}

	private static function convertSection(array $section): array
	{
		$data = $section['menuData'] ?? [];

		return [
			'ID' => $data['menu_item_id'] ?? $section['id'],
			'TEXT' => $section['title'],
			'URL' => $section['url'] ?? '',
			'EXTRA_URLS' => $section['extraUrls'] ?? [],
			'COUNTER_ID' => $data['counter_id'] ?? '',
			'SUB_LINK' => $data['sub_link'] ?? '',
			'ITEMS' => $data['sub_menu'] ?? [],
			'ON_CLICK' => $section['onclick'] ?? $data['onclick'] ?? '',
		];
	}

	private static function applyCounters(array $items, array $counters): array
	{
		foreach ($items as &$item)
		{
			$counterId = $item['COUNTER_ID'] ?? '';
			if ($counterId !== '' && !in_array($counterId, ['im-message', 'notification'], true))
			{
				$item['COUNTER'] = $counters[$counterId] ?? 0;
			}
			if (!empty($item['ITEMS']))
			{
				$item['ITEMS'] = self::applyCounters($item['ITEMS'], $counters);
			}
		}

		return $items;
	}

	public static function isModelAvailable(): bool
	{
		return self::getAvailableChatItems() !== [];
	}

	private static function getAvailableChatItems(): array
	{
		if (!self::isEnabled())
		{
			return [];
		}

		$items = Integration\Im\CollaborationMenu::getItems();

		return in_array('messenger', array_column($items, 'ID'), true) ? $items : [];
	}

	private static function isContextAvailable(string $context): bool
	{
		return in_array($context, ['messenger', 'collaboration'], true)
			&& Context::getCurrent()->getRequest()->getQuery('IFRAME') !== 'Y';
	}

	private static function isEnabled(): bool
	{
		$extranetService = new Integration\Extranet\ExtranetService();
		if (
			!defined('AIR_SITE_TEMPLATE')
			|| $extranetService->isExtranet()
			|| $extranetService->isCollaber((int)CurrentUser::get()->getId())
			|| !Integration\Bitrix24\CollaborationWebMenu::isEnabled()
		)
		{
			return false;
		}

		return true;
	}
}
