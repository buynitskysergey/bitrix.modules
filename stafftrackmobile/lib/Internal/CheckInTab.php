<?php

namespace Bitrix\StaffTrackMobile\Internal;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Tab\Tabable;
use Bitrix\Mobile\Tab\Utils;
use Bitrix\MobileApp\Janative\Manager;
use Bitrix\StaffTrack\Feature as StaffTrackFeature;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;
use Bitrix\Timeman\V2\Public\Provider\FullReportProvider;
use Bitrix\TimemanMobile\Public\Features\WorkReportsFeature;
use Bitrix\Main\Config\Option;

class CheckInTab implements Tabable
{
	private Context $context;
	private ?array $tabItems = null;
	private ?array $timemanDependentTabs = null;
	private ?bool $isTimemanAvailable = null;
	private ?CheckInSettingsProvider $settingsProvider = null;

	public static function onBeforeTabsGet(): array
	{
		return [
			[
				'code' => 'check_in',
				'class' => static::class,
			],
		];
	}

	/**
	 * @throws LoaderException
	 * @throws \Exception
	 */
	public function getData(): ?array
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		return $this->getDataInternal();
	}

	/**
	 * @throws \Exception
	 */
	public function getMenuData(): ?array
	{
		return [
			'id' => $this->getId(),
			'title' => $this->getTitle(),
			'imageName' => $this->getIconId(),
			'useLetterImage' => true,
			'section_code' => 'teamwork',
			'params' => [
				'id' => 'stafftrack_check_in',
				'onclick' => Utils::getComponentJSCode($this->getDataInternal()['component']),
			],
			'tag' => 'new',
		];
	}

	/**
	 * @throws LoaderException
	 */
	public function isAvailable(): bool
	{
		if ($this->context->isCollaber || $this->context->extranet)
		{
			return false;
		}

		return
			Loader::includeModule('intranet')
			&& Loader::includeModule('stafftrack')
			&& Loader::includeModule('stafftrackmobile')
			&& Feature::isEnabled(CheckInFeature::class)
			&& $this->hasVisibleTabs();
	}

	public function getId(): string
	{
		return 'check-in';
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('STAFFTRACKMOBILE_TAB_NAME_CHECK_IN');
	}

	public function getShortTitle(): ?string
	{
		return Loc::getMessage('STAFFTRACKMOBILE_TAB_NAME_CHECK_IN');
	}

	public function shouldShowInMenu(): bool
	{
		return $this->isAvailable();
	}

	public function canBeRemoved(): bool
	{
		return true;
	}

	public function canChangeSort(): bool
	{
		return true;
	}

	public function defaultSortValue(): int
	{
		return 400;
	}

	/**
	 * @param Context $context
	 * @return void
	 */
	public function setContext($context): void
	{
		$this->context = $context;
		$this->tabItems = null;
		$this->timemanDependentTabs = null;
		$this->isTimemanAvailable = null;
		$this->settingsProvider = null;
	}

	public function getIconId(): string
	{
		return 'location';
	}

	private function getDataInternal(): array
	{
		return [
			'id' => 'check-in',
			'badgeCode' => 'check-in',
			'sort' => 400,
			'imageName' => $this->getIconId(),
			'component' => $this->getTabsComponent(),
		];
	}

	private function getTabsComponent(): array
	{
		$items = $this->getTabItems();

		$unavailableTabIds = $this->isTimemanAvailable()
			? []
			: array_column($this->getTimemanDependentTabs(), 'id');

		$componentCode = StaffTrackFeature::isCheckInRolloutEnabled((int)$this->context->userId)
			? 'stafftrack.check-in-v3.tabs'
			: 'stafftrack.check-in-v2.tabs';

		return [
			'name' => 'JSStackComponent',
			'title' => $this->getTitle(),
			'componentCode' => $componentCode,
			'scriptPath' => Manager::getComponentPath('stafftrack:' . $componentCode),
			'rootWidget' => [
				'name' => 'tabs',
				'settings' => [
					'objectName' => 'tabs',
					'grabTitle' => false,
					'grabButtons' => true,
					'grabSearch' => true,
					'useLargeTitleMode' => true,
					'tabs' => [
						'items' => $items,
					],
				],
			],
			'params' => [
				'COMPONENT_CODE' => $componentCode,
				'USER_ID' => $this->context->userId,
				'SITE_ID' => $this->context->siteId,
				'UNAVAILABLE_TAB_IDS' => $unavailableTabIds,
			],
		];
	}

	private function getTabItems(): array
	{
		if ($this->tabItems === null)
		{
			$this->tabItems = array_merge(
				array_filter([$this->getShiftsTab()]),
				$this->getTimemanDependentTabs()
			);
		}

		return $this->tabItems;
	}

	private function hasVisibleTabs(): bool
	{
		return StaffTrackFeature::isGeoFeaturesAvailableByRegion()
			|| $this->hasTimemanDependentTabs();
	}

	private function getTimemanDependentTabs(): array
	{
		if ($this->timemanDependentTabs === null)
		{
			$this->timemanDependentTabs = $this->hasTimemanDependentTabs()
				? array_values(array_filter([
					$this->getWorkDaysTab(),
					$this->getWorkReportsTab($this->hasPendingDraftReport()),
				]))
				: [];
		}

		return $this->timemanDependentTabs;
	}

	private function hasTimemanDependentTabs(): bool
	{
		return $this->isTimemanAvailable()
			|| $this->getSettingsProvider()->isTimemanAllowedByLicense();
	}

	private function isTimemanAvailable(): bool
	{
		if ($this->isTimemanAvailable === null)
		{
			$settingsProvider = $this->getSettingsProvider();

			$this->isTimemanAvailable = $settingsProvider->isTimemanAvailable()
				&& Loader::includeModule('timemanmobile')
				&& $settingsProvider->isWorkTimeToolAvailable();
		}

		return $this->isTimemanAvailable;
	}

	private function getSettingsProvider(): CheckInSettingsProvider
	{
		return $this->settingsProvider ??= new CheckInSettingsProvider();
	}

	private function hasPendingDraftReport(): bool
	{
		if (
			!Feature::isEnabled(WorkReportsFeature::class)
			|| !Loader::includeModule('timeman')
		)
		{
			return false;
		}

		$provider = new FullReportProvider();
		if (!method_exists($provider, 'getReportToSend'))
		{
			return false;
		}

		return $provider
			->getReportToSend($this->context->userId, true, true)
			->isReadyForSubmit === true
		;
	}

	private function getShiftsTab(): array
	{
		if (!StaffTrackFeature::isGeoFeaturesAvailableByRegion())
		{
			return [];
		}

		return [
			'id' => 'shifts',
			'title' => Loc::getMessage('STAFFTRACKMOBILE_TAB_NAVIGATION_TAB_SHIFTS'),
			'spotlightId' => 'stafftrack-aha-shifts-tab',
			'disableScroll' => true,
			'widget' => [
				'name' => 'layout',
				'code' => 'shifts',
				'settings' => [
					'objectName' => 'layout',
				],
			],
		];
	}

	private function getWorkReportsTab(bool $hasPendingDraftReport = false): array
	{
		if (Option::get('timemanmobile', 'work_reports_enabled', 'N') !== 'Y')
		{
			return [];
		}

		return [
			'id' => 'work-reports',
			'title' => Loc::getMessage('STAFFTRACKMOBILE_TAB_NAVIGATION_TAB_WORK_REPORTS'),
			'label' => $hasPendingDraftReport ? '1' : '',
			'spotlightId' => 'stafftrack-aha-work-reports-tab',
			'widget' => [
				'name' => 'layout',
				'code' => 'work-reports',
				'settings' => [
					'objectName' => 'layout',
				],
			],
		];
	}

	private function getWorkDaysTab(): array
	{
		return [
			'id' => 'work-days',
			'title' => Loc::getMessage('STAFFTRACKMOBILE_TAB_NAVIGATION_TAB_WORK_DAYS'),
			'spotlightId' => 'stafftrack-aha-work-days-tab',
			'widget' => [
				'name' => 'layout',
				'code' => 'work-days',
				'settings' => [
					'objectName' => 'layout',
				],
			],
		];
	}
}
