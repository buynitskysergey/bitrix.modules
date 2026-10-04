<?php

namespace Bitrix\Mobile\AppTabs;

use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\Util;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Tab\Tabable;
use Bitrix\Mobile\Tab\Utils;
use Bitrix\MobileApp\Janative\Manager;

class Crm implements Tabable
{
	private const INITIAL_COMPONENT = 'crm:crm.tabs';

	/** @var Context $context */
	private Context $context;

	public function isAvailable(): bool
	{
		if (
			!Loader::includeModule('crm')
			|| !Loader::includeModule('crmmobile')
		)
		{
			return false;
		}

		if (!Container::getInstance()->getIntranetToolsManager()->checkCrmAvailability())
		{
			return false;
		}

		if (Loader::includeModule('intranet') && !Util::isIntranetUser((int)$this->context->userId))
		{
			return false;
		}

		return \CCrmPerms::IsAccessEnabled();
	}

	public function getData(): ?array
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		return [
			'id' => 'crm',
			'sort' => $this->defaultSortValue(),
			'imageName' => 'crm',
			'badgeCode' => 'crm_all_no_orders',
			'component' => $this->getComponentParams(),
		];
	}

	public function shouldShowInMenu(): bool
	{
		return $this->isAvailable();
	}

	public function getMenuData(): ?array
	{
		return [
			'id' => 'crm',
			'sort' => 100,
			'section_code' => 'crm',
			'title' => $this->getTitle(),
			'useLetterImage' => true,
			'color' => '#00ace3',
			'imageUrl' => 'favorite/icon-crm.png',
			'imageName' => $this->getIconId(),
			'params' => [
				'id' => 'crm_tabs',
				'onclick' => Utils::getComponentJSCode($this->getComponentParams()),
				'counter' => 'crm_all_no_orders',
				'analytics' => [
					'tool' => 'crm',
					'category' => 'entity_operations',
					'event' => 'open_section',
					'c_section' => 'ava_menu',
				],
			],
		];
	}

	public function canBeRemoved(): bool
	{
		return true;
	}

	public function defaultSortValue(): int
	{
		return 500;
	}

	public function canChangeSort(): bool
	{
		return true;
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('TAB_NAME_CRM');
	}

	public function setContext($context): void
	{
		$this->context = $context;
	}

	public function getShortTitle(): ?string
	{
		return Loc::getMessage('TAB_NAME_CRM');
	}

	public function getId(): string
	{
		return 'crm';
	}

	public function getIconId(): string
	{
		return $this->getId();
	}

	private function getComponentParams(): array
	{
		return [
			'name' => 'JSStackComponent',
			'title' => Loc::getMessage('TAB_NAME_CRM'),
			'componentCode' => self::INITIAL_COMPONENT,
			'scriptPath' => Manager::getComponentPath(self::INITIAL_COMPONENT),
			'rootWidget' => [
				'name' => 'layout',
				'settings' => [
					'objectName' => 'layout',
					'useLargeTitleMode' => true,
				],
			],
			'params' => [],
		];
	}
}
