<?php

namespace Bitrix\Mobile\AppTabs;

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Menu\Analytics;
use Bitrix\Mobile\Tab\Tabable;
use Bitrix\Mobile\Tab\Utils;
use Bitrix\MobileApp\Janative\Manager;

final class Mail implements Tabable
{
	private const INITIAL_COMPONENT = 'mail:mail.message.grid';
	private const WESTERN_RELEASE_DATA = '27.11.2025 10:00';
	private const EXPIRE_DAYS_TS = 40 * 24 * 60 * 60;

	public function isAvailable()
	{
		return (
			$this->isToolAvailable('mail')
			&& Loader::includeModule('mail')
			&& Loader::includeModule('mailmobile')
		);
	}

	private function isToolAvailable(string $toolId): bool
	{
		if (Loader::includeModule('intranet'))
		{
			return ToolsManager::getInstance()->checkAvailabilityByToolId($toolId);
		}

		return true;
	}

	public function getData()
	{
		return [
			'id' => $this->getId(),
			'sort' => $this->defaultSortValue(),
			'imageName' => $this->getIconId(),
			'badgeCode' => $this->getCounterId(),
			'component' => $this->getComponentParams(),
		];
	}

	public function getMenuData(): ?array
	{
		$menuData = [
			'id' => $this->getId(),
			'sort' => 640,
			'section_code' => 'teamwork',
			'title' => $this->getTitle(),
			'useLetterImage' => true,
			'color' => '#00ace3',
			'imageUrl' => 'mail/mail.png',
			'imageName' => $this->getIconId(),
			'params' => [
				'id' => 'mail_tabs',
				'onclick' => Utils::getComponentJSCode($this->getComponentParams()),
				'counter' => $this->getCounterId(),
				'analytics' => Analytics::mail(),
			],
		];

		if (!$this->isTagNewExpired())
		{
			$menuData['tag'] = 'new';
		}

		return $menuData;
	}

	private function getComponentParams(): array
	{
		return [
			'name' => 'JSStackComponent',
			'title' => Loc::getMessage($this->getTitle()),
			'componentCode' => self::INITIAL_COMPONENT,
			'scriptPath' => Manager::getComponentPath(self::INITIAL_COMPONENT),
			'rootWidget' => [
				'name' => 'layout',
				'settings' => [
					'objectName' => 'layout',
					'useLargeTitleMode' => false,
				],
			],
			'params' => [],
		];
	}

	private function isTagNewExpired(): bool
	{

		$westernReleaseDate = \DateTime::createFromFormat(
			'd.m.Y H:i',
			self::WESTERN_RELEASE_DATA,
			new \DateTimeZone('Europe/Moscow')
		);

		$expireTs = $westernReleaseDate->getTimestamp() + self::EXPIRE_DAYS_TS;

		return time() > $expireTs;
	}

	public function shouldShowInMenu()
	{
		return $this->isAvailable();
	}

	public function canBeRemoved()
	{
		return true;
	}

	public function defaultSortValue()
	{
		return 500;
	}

	public function canChangeSort()
	{
		return true;
	}

	public function getTitle()
	{
		return Loc::getMessage('TAB_NAME_MAIL');
	}

	public function getShortTitle()
	{
		return Loc::getMessage('TAB_NAME_MAIL');
	}

	public function getId()
	{
		return 'mail_list';
	}

	public function getIconId(): string
	{
		return 'mail';
	}

	public function getCounterId(): string
	{
		return 'mail_unseen';
	}

	public function setContext($context)
	{
		$this->context = $context;
	}
}
