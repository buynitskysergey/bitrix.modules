<?php

namespace Bitrix\Mobile\AppTabs;

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Tab\Tabable;
use Bitrix\MobileApp\Janative\Manager;
use Bitrix\Socialnetwork\Helper\Path;
use Bitrix\Mobile\Menu\Analytics;

class Stream implements Tabable
{
	private $context;

	public function isAvailable()
	{
		$result = true;

		if ($this->context->isCollaber)
		{
			return false;
		}

		if (Loader::includeModule('intranet'))
		{
			$result = ToolsManager::getInstance()->checkAvailabilityByToolId('news');
		}

		return $result;
	}

	public function getData()
	{
		return $this->getDataInternal();
	}

	/**
	 * @return boolean
	 */
	public function shouldShowInMenu()
	{
		return $this->isAvailable();
	}

	/**
	 * @return null|array
	 */
	public function getMenuData()
	{
		$data = $this->getDataInternal();
		$result = [
			'id' => $this->getId(),
			'sort' => 120,
			'section_code' => 'teamwork',
			'title' => $this->getTitle(),
			'useLetterImage' => true,
			'color' => '#40465A',
			'imageUrl' => 'favorite/stream.png',
			'imageName' => $this->getIconId(),
			'params' => [
				'counter' =>'**',
				'analytics' => Analytics::stream(),
			],
		];

		if ($data['component'])
		{
			$result['params']['onclick'] = \Bitrix\Mobile\Tab\Utils::getComponentJSCode($data['component']);
		}
		elseif ($data['page'])
		{
			$result['params'] = $data['page'];
		}

		return $result;
	}

	private function getDataInternal(): array
	{
		$newsWebPath = $this->context->siteDir . 'mobile/index.php?version=' . $this->context->version;
		$apiVersion = \Bitrix\MobileApp\Mobile::getApiVersion();

		if ($apiVersion < 41)
		{
			return [
				'sort' => 200,
				'imageName' => $this->getIconId(),
				'badgeCode' => 'stream',
				'page' => [
					'url' => $newsWebPath,
					'titleParams' => [
						'useLargeTitleMode' => true,
						'text' => $this->getTitle()
					],
					'useSearchBar' => true,
				],
			];
		}

		$calendarWebPathTemplate = Path::get('user_calendar_path_template');
		if ($calendarWebPathTemplate === '')
		{
			$calendarWebPathTemplate = SITE_DIR . '/company/personal/user/#ID#/calendar/';
		}

		$calendarWebPath = str_replace([ '#ID#', '#USER_ID#', '#user_id#' ], $this->context->userId, $calendarWebPathTemplate);

		return [
			'sort' => 200,
			'imageName' => $this->getIconId(),
			'badgeCode' => 'stream',
			'id' => 'stream',
			'component' => [
				'name' => 'JSStackComponent',
				'title' => Loc::getMessage('TAB_STREAM_NAVIGATION_HEADER'),
				'componentCode' => 'stream.tabs',
				'scriptPath' => Manager::getComponentPath('stream.tabs'),
				'rootWidget' => [
					'name' => 'web',
					'settings' => [
						'objectName' => 'tabs',
						'titleParams' => [
							'useLargeTitleMode' => true,
							'text' => Loc::getMessage('TAB_STREAM_NAVIGATION_TAB_STREAM2'),
						],
						'grabTitle' => false,
						'grabButtons' => true,
						'grabSearch' => true,
						'page' => [
							'preload' => false,
							'url' => $newsWebPath,
							'useSearchBar' => true,
						],
					],
				],
				'params' => [
					'COMPONENT_CODE' => 'stream.tabs',
					'SINGLE_FEED' => true,
					'USER_ID' => $this->context->userId,
					'SITE_ID' => $this->context->siteId,
					'CALENDAR_WEB_PATH' => $calendarWebPath,
					'VIDEO_WEB_PATH' => SITE_DIR . 'conference/',
				],
			],
		];
	}

	private function getAllowedFeatures(): array
	{
		$result = [];

		if (!Loader::includeModule('socialnetwork'))
		{
			return $result;
		}

		$activeUserFeaturesList = \CSocNetFeatures::getActiveFeatures(SONET_ENTITY_USER, $this->context->userId);
		$allowedFeaturesList = \CSocNetAllowed::getAllowedFeatures();

		foreach ([ 'files', 'calendar' ] as $feature)
		{
			if ($feature === 'calendar')
			{
				$allowed = (
					isset($allowedFeaturesList[$feature]['allowed'])
					&& (
						(
							in_array(SONET_ENTITY_USER, $allowedFeaturesList[$feature]['allowed'], true)
							&& is_array($activeUserFeaturesList)
							&& in_array($feature, $activeUserFeaturesList, true)
						)
						|| in_array(SONET_ENTITY_GROUP, $allowedFeaturesList[$feature]['allowed'], true)
					)
				);
			}
			else
			{
				$allowed = (
					isset($allowedFeaturesList[$feature]['allowed'])
					&& in_array(SONET_ENTITY_USER, $allowedFeaturesList[$feature]['allowed'], true)
					&& is_array($activeUserFeaturesList)
					&& in_array($feature, $activeUserFeaturesList)
				);
			}

			if ($allowed)
			{
				$result[] = $feature;
			}
		}

		return $result;
	}

	public function canBeRemoved()
	{
		return true;
	}

	/**
	 * @return integer
	 */
	public function defaultSortValue()
	{
		return 200;
	}

	public function canChangeSort()
	{
		return true;
	}

	public function getTitle()
	{
		return Loc::getMessage("TAB_NAME_NEWS2");
	}

	public function setContext($context)
	{
		$this->context = $context;
	}

	public function getShortTitle()
	{
		return Loc::getMessage("TAB_NAME_NEWS_SHORT2");
	}

	public function getId()
	{
		return "news";
	}

	public function getIconId(): string
	{
		return 'newsfeed';
	}

}
