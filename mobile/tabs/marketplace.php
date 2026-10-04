<?php

namespace Bitrix\Mobile\AppTabs;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Feature\MobileMarketFeature;
use Bitrix\Mobile\Menu\Analytics;
use Bitrix\Mobile\Tab\Tabable;
use Bitrix\Mobile\Tab\Utils;
use Bitrix\MobileApp\Janative\Manager;

class Marketplace implements Tabable
{
	private const PAGE_PATH = '/mobile/market/';
	private const INITIAL_COMPONENT = 'market.home';

	/** @var Context */
	private $context;

	public function isAvailable(): bool
	{
		return Feature::isEnabled(MobileMarketFeature::class)
			&& !$this->context->extranet
			&& !$this->context->isCollaber
			&& Loader::includeModule('market')
			&& Loader::includeModule('rest');
	}

	public function getData(): ?array
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		return [
			'id' => $this->getId(),
			'sort' => $this->defaultSortValue(),
			'imageName' => $this->getIconId(),
			'badgeCode' => $this->getId(),
			'component' => $this->getComponentParams(),
		];
	}

	public function getMenuData(): ?array
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		return [
			'id' => $this->getId(),
			'sort' => 100,
			'sectionCode' => 'marketplace',
			'section_code' => 'marketplace',
			'title' => $this->getTitle(),
			'imageName' => $this->getIconId(),
			'params' => [
				'onclick' => Utils::getComponentJSCode($this->getComponentParams()),
				'analytics' => Analytics::menuOpen('market', 'marketplace'),
			],
		];
	}

	public function shouldShowInMenu(): bool
	{
		return $this->isAvailable();
	}

	public function canBeRemoved(): bool
	{
		return true;
	}

	public function defaultSortValue(): int
	{
		return 600;
	}

	public function canChangeSort(): bool
	{
		return true;
	}

	public function getTitle(): ?string
	{
		return $this->getMarketplaceTitle();
	}

	public function setContext($context): void
	{
		$this->context = $context;
	}

	public function getShortTitle(): ?string
	{
		return $this->getMarketplaceTitle();
	}

	public function getId(): string
	{
		return 'marketplace';
	}

	public function getIconId(): string
	{
		return 'market';
	}

	private function getPageUrl(): string
	{
		return self::PAGE_PATH;
	}

	private function getMarketplaceTitle(): string
	{
		return (string)(Loc::getMessage('TAB_NAME_MARKETPLACE') ?? Loc::getMessage('TAB_NAME_APPS'));
	}

	private function getComponentParams(bool $withBackdrop = false): array
	{
		$componentCode = self::INITIAL_COMPONENT;
		$scriptPath = Manager::getComponentPath($componentCode);

		if (empty($scriptPath))
		{
			return $this->getWebComponentParams($withBackdrop);
		}

		$settings = [
			'objectName' => 'layout',
			'useLargeTitleMode' => true,
			'titleParams' => [
				'useLargeTitleMode' => true,
				'text' => $this->getMarketplaceTitle(),
				'type' => 'section',
			],
		];

		if ($withBackdrop)
		{
			$settings['modal'] = true;
			$settings['backdrop'] = $this->getBackdropSettings();
		}

		return [
			'name' => 'JSStackComponent',
			'title' => $this->getMarketplaceTitle(),
			'componentCode' => $componentCode,
			'scriptPath' => $scriptPath,
			'rootWidget' => [
				'name' => 'layout',
				'settings' => $settings,
			],
			'params' => [
				'TITLE' => $this->getMarketplaceTitle(),
				'PAGE_URL' => $this->getPageUrl(),
			],
		];
	}

	private function getWebComponentParams(bool $withBackdrop = false): array
	{
		$pageUrl = $this->getPageUrl();
		$settings = [
			'titleParams' => [
				'useLargeTitleMode' => true,
				'text' => $this->getMarketplaceTitle(),
			],
			'page' => [
				'preload' => false,
				'url' => $pageUrl,
			],
			'useSearch' => true,
		];

		if ($withBackdrop)
		{
			$settings['modal'] = true;
			$settings['backdrop'] = $this->getBackdropSettings();
		}

		return [
			'name' => 'JSStackComponent',
			'title' => $this->getMarketplaceTitle(),
			'componentCode' => 'web: ' . $pageUrl,
			'scriptPath' => '',
			'rootWidget' => [
				'name' => 'web',
				'settings' => $settings,
			],
			'params' => [],
		];
	}

	private function getBackdropSettings(): array
	{
		return [
			'onlyMediumPosition' => false,
			'mediumPositionPercent' => 100,
			'swipeAllowed' => false,
		];
	}
}
