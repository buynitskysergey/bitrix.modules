<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Menu\MenuList;
use Bitrix\Mobile\Menu\AhaMoment;
use Bitrix\Mobile\Menu\Service\MenuListCache;
use Bitrix\Mobile\Menu\Service\SupportBanners;
use Bitrix\Mobile\Menu\Service\IntegratorProvider;
use Bitrix\Mobile\Internal\Dev\ImplementationSpecialistsSandbox;
use Bitrix\Mobile\Menu\Promo\FloatingPromoProvider;
use Bitrix\Mobile\Menu\Purchases\PurchasesCardProvider;
use Bitrix\Mobile\Menu\Tariff\TariffPlateProvider;
use Bitrix\Mobile\Internal\Integration\HumanResources\SalaryVacationService;
use Bitrix\Mobile\Provider\CommonUserDto;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Mobile\Provider\ThemeProvider;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\BitrixAnswersFeature;
use Bitrix\Mobile\Feature\ImplementationSpecialistsFeature;
use Bitrix\Mobile\Feature\PersonalAccountFeature;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;
use Bitrix\StaffTrack\Public\Provider\CheckInProvider;

class Menu extends Controller
{
	public function configureActions()
	{
		return [
			'getMenu' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getInitialMenuData' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'getImplementationSpecialistsList' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/*
	 * @restMethod mobile.Menu.getMenu
	 */
	public function getMenuAction(bool $forceRefresh = false): array
	{
		if (!Loader::includeModule('mobileapp'))
		{
			return [];
		}
		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$userId)
		{
			return [];
		}

		$user = $this->getUserData($userId);
		if (!$user)
		{
			return [];
		}

		global $USER;

		$context = new Context([
			'userId' => $userId,
			'extranet' => $user->isExtranet,
			'isCollaber' => $user->isCollaber,
		]);
		$cache = new MenuListCache($context->userId);

		$supportBotId = $this->getSupportBotId();
		$currentTheme = (new ThemeProvider($userId))->getCurrentTheme();

		$canUseTimeMan = $this->canUseTimeMan($context);

		$checkInData = $this->getCheckInData($user);

		$personalAccount = $this->getPersonalAccount($user);

		$response = [
			'user' => $user,
			'menuList' => $user->isGuest ? [] : (new MenuList($context, $cache))->build($forceRefresh),
			'currentShift' => $this->getCurrentShift($user),
			'company' => $this->getCompany($user),
			'implementationSpecialists' => $this->getImplementationSpecialists($user),
			'integrator' => $this->getIntegrator($user),
			'personalAccount' => [
				'companies' => $personalAccount['companies'],
			],
			'helpdeskUrl' => Loader::includeModule('ui') ? \Bitrix\UI\Util::getHelpdeskUrl(true) : null,
			'supportBotId' => $supportBotId,
			'supportBanners' => $this->getSupportBanners(),
			'tariffPlate' => $this->getTariffPlate(),
			'floatingPromo' => $this->getFloatingPromo($user),
			'ahaMoment' => $this->getAhaMoment(),
			'currentTheme' => $currentTheme,
			'isNewCheckInEnabled' => $checkInData['isNewCheckInEnabled'],
			'manualCheckIns' => $checkInData['manualCheckIns'],

			'restrictions' => [
				'canOpenProfile' => !$user->isGuest,
				'canEditProfile' => $USER->CanDoOperation('edit_own_profile'),
				'canUseTimeMan' => $canUseTimeMan,
				'canUseCheckIn' => $checkInData['canUseCheckIn'],
				'canUseSupport' => $supportBotId > 0,
				'canInvite' => $this->canInvite(),
				'canUsePersonalAccount' => $personalAccount['canUsePersonalAccount'],
				'canUsePurchases' => $this->getCanUsePurchases($user),
				'canUseTelephony' => \Bitrix\Main\Loader::includeModule('voximplant') && \Bitrix\Voximplant\Security\Helper::canCurrentUserPerformCalls(),
				'shouldShowWhatsNew' => \Bitrix\Mobile\Config\Feature::isEnabled(\Bitrix\Mobile\Feature\WhatsNewFeature::class),
				'shouldShowBitrixAnswers' => $this->shouldShowBitrixAnswers($user),
				'canUseSecuritySettings' => $user->isGuest ? false : \Bitrix\Mobile\Config\Feature::isEnabled(\Bitrix\Mobile\Feature\SecuritySettingsFeature::class),
				'canUseTabPresetSettings' => !$user->isGuest,
				'canUseNotificationSettings' => !$user->isGuest,
				'canGoToWeb' => !$user->isGuest,
			],
		];

		return $response;
	}

	private function getCheckInData(CommonUserDto $user): ?array
	{
		$isNewCheckInEnabled = false;
		$manualCheckIns = [];
		$canUseCheckIn = $this->canUseCheckIn($user);

		if ($canUseCheckIn)
		{
			$isNewCheckInEnabled =  Feature::isEnabled(CheckInFeature::class);
			$manualCheckIns = $isNewCheckInEnabled
				? (new CheckInProvider())->getCurrentUserManualCheckInsByDate($user->id, time())
				: [];
		}

		return [
			'isNewCheckInEnabled' => $isNewCheckInEnabled,
			'manualCheckIns' => $manualCheckIns,
			'canUseCheckIn' => $canUseCheckIn,
		];
	}

	private function getUserData(int $userId): ?\Bitrix\Mobile\Provider\CommonUserDto
	{
		$userData = \CUser::getById($userId)->Fetch();
		if (!$userData || !is_array($userData))
		{
			return null;
		}

		$user = [
			'ID' => $userId,
			'LOGIN' => $userData['LOGIN'],
			'NAME' => $userData['NAME'],
			'LAST_NAME' => $userData['LAST_NAME'],
			'SECOND_NAME' => $userData['SECOND_NAME'],
			'EMAIL' => $userData['EMAIL'] ?? '',
			'WORK_PHONE' => $userData['WORK_PHONE'],
			'WORK_POSITION' => $userData['WORK_POSITION'],
			'PERSONAL_MOBILE' => $userData['PERSONAL_MOBILE'],
			'PERSONAL_PHONE' => $userData['PERSONAL_PHONE'],
			'PERSONAL_GENDER' => $userData['PERSONAL_GENDER'],
			'PERSONAL_PHOTO' => $userData['PERSONAL_PHOTO'],
			'EXTERNAL_AUTH_ID' => $userData['EXTERNAL_AUTH_ID'],
		];

		return UserRepository::createUserDto($user);
	}

	private function getCurrentShift(CommonUserDto $user): ?\Bitrix\StaffTrack\Model\Shift
	{
		if (!$this->canUseCheckIn($user))
		{
			return null;
		}

		$offset = \CTimeZone::GetOffset();
		$date = \Bitrix\Main\Type\Date::createFromTimestamp(time() + $offset);
		$dateFormatted = $date->format('d.m.Y');

		return \Bitrix\StaffTrack\Provider\ShiftProvider::getInstance($user->id)->findByDate($dateFormatted);
	}

	private function canUseCheckIn(CommonUserDto $user): bool
	{
		if ($user->isCollaber || $user->isExtranet || $user->isGuest)
		{
			return false;
		}

		return Loader::includeModule('stafftrack')
			&& \Bitrix\StaffTrack\Feature::isCheckInEnabled()
			&& \Bitrix\StaffTrack\Feature::isGeoFeaturesAvailableByRegion();
	}

	private function getSupportBotId(): int
	{
		if (!\Bitrix\Mobile\Config\Feature::isEnabled(\Bitrix\Mobile\Feature\SupportFeature::class))
		{
			return 0;
		}

		return  (int)(new \Bitrix\Mobile\Provider\SupportProvider())->getBotId();
	}

	private function getSupportBanners(): array
	{
		$service = new SupportBanners();

		return [
			'shouldShow' => $service->shouldShowSupportBanners(),
			'formCode' => $service->getFormCode(),
		];
	}

	private function getTariffPlate(): array
	{
		return (new TariffPlateProvider())->getData();
	}

	private function getFloatingPromo(CommonUserDto $user): array
	{
		return (new FloatingPromoProvider($user))->getData();
	}

	private function canInvite(): bool
	{
		return (
			Loader::includeModule('intranet')
			&& Loader::includeModule('intranetmobile')
			&& \Bitrix\Intranet\Invitation::canCurrentUserInvite());
	}

	private function canUseTimeMan(Context $context): bool
	{
		return !$context->extranet
			&& Loader::includeModule('intranet')
			&& ToolsManager::getInstance()->checkAvailabilityByToolId('worktime')
			&& Loader::includeModule('timeman')
			&& \CTimeMan::CanUse()
		;
	}

	private function getCompany(CommonUserDto $user): ?array
	{
		if ($user->isExtranet || $user->isCollaber || $user->isGuest)
		{
			return null;
		}

		if (
			!Loader::includeModule('intranet')
			|| !Loader::includeModule('intranetmobile')
			|| !Loader::includeModule('humanresources')
		)
		{
			return null;
		}

		$departmentProvider = new \Bitrix\IntranetMobile\Provider\DepartmentProvider();

		$departmentUserIds = $departmentProvider->getEmployeesFromUserDepartments($user->id, 4);

		if (count($departmentUserIds) < 4)
		{
			$allUserIds = $departmentProvider->getAllEmployees(0, 8);

			$userIds = array_unique(array_merge($departmentUserIds, $allUserIds));
			$userIds = array_slice($userIds, 0, 4);
		}
		else
		{
			$userIds = $departmentUserIds;
		}

		$users = UserRepository::getByIds($userIds);

		$totalUsersCount = $departmentProvider->getTotalEmployeeCount();

		return [
			'users' => $users,
			'totalUsersCount' => $totalUsersCount,
			'canInvite' => true,
		];
	}

	private function getImplementationSpecialists(CommonUserDto $user): ?array
	{
		if (!$this->passesImplementationSpecialistsGate($user))
		{
			return null;
		}

		// The dev sandbox substitutes the section flags and users, never the rollout gate above it.
		$sandbox = new ImplementationSpecialistsSandbox($user->id);
		if ($sandbox->isEnabled())
		{
			return $sandbox->getSection();
		}

		global $USER;

		$canView = (bool)$USER->CanDoOperation('edit_all_users');
		$canInvite = \Bitrix\Intranet\Invitation::canCurrentUserInvite();

		if (!$canView && !$canInvite)
		{
			return null;
		}

		$provider = new \Bitrix\IntranetMobile\Provider\ImplementationSpecialistProvider();

		$data = $canView
			? $provider->getSpecialists()
			: [
				'users' => [],
				'totalCount' => 0,
				'canInviteMore' => $provider->canInviteMore(),
			];

		return [
			'canView' => $canView,
			'canInvite' => $canInvite,
			'canInviteMore' => $data['canInviteMore'],
			'canInviteNew' => $canInvite
				&& $data['canInviteMore']
				&& \Bitrix\Intranet\Invitation::isNewInvitationAvailable(),
			'isRenamed' => \Bitrix\Intranet\Public\Service\IntegratorService::createByDefault()->isRenamedIntegrator(),
			'totalCount' => $data['totalCount'],
			'users' => $data['users'],
		];
	}

	/**
	 * Full specialists list data (users + per-user companion) fetched lazily when the list opens,
	 * so the heavy per-user companion loop stays off the menu payload.
	 *
	 * @restMethod mobile.Menu.getImplementationSpecialistsList
	 */
	public function getImplementationSpecialistsListAction(): array
	{
		$empty = ['users' => [], 'extra' => []];

		if (!Loader::includeModule('mobileapp'))
		{
			return $empty;
		}

		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$userId)
		{
			return $empty;
		}

		$user = $this->getUserData($userId);
		if (!$user || !$this->passesImplementationSpecialistsGate($user))
		{
			return $empty;
		}

		// Same order as the section: the sandbox substitutes the list data, not the rollout gate.
		$sandbox = new ImplementationSpecialistsSandbox($userId);
		if ($sandbox->isEnabled())
		{
			return $sandbox->getListData();
		}

		global $USER;
		if (!$USER->CanDoOperation('edit_all_users'))
		{
			return $empty;
		}

		return (new \Bitrix\IntranetMobile\Provider\ImplementationSpecialistProvider())->getListData();
	}

	private function passesImplementationSpecialistsGate(CommonUserDto $user): bool
	{
		if (Feature::isDisabled(ImplementationSpecialistsFeature::class))
		{
			return false;
		}

		if ($user->isExtranet || $user->isCollaber || $user->isGuest)
		{
			return false;
		}

		return Loader::includeModule('intranet')
			&& Loader::includeModule('intranetmobile')
			&& Loader::includeModule('bitrix24');
	}

	private function getIntegrator(CommonUserDto $user): ?array
	{
		if ($user->isExtranet || $user->isCollaber || $user->isGuest)
		{
			return null;
		}

		return (new IntegratorProvider())->getIntegrator($user);
	}

	private function getCanUsePurchases(CommonUserDto $user): bool
	{
		return (new PurchasesCardProvider())->isVisibleForCurrentUser($user);
	}

	/**
	 * Personal account (salary/vacation) company list and its visibility flag.
	 * A disabled feature flag short-circuits before any humanresources call.
	 * One humanresources call per menu render: the flag is derived from the list
	 * (empty list also covers the disabled region gate).
	 *
	 * @return array{canUsePersonalAccount: bool, companies: list<array>}
	 */
	private function getPersonalAccount(CommonUserDto $user): array
	{
		if (
			!Feature::isEnabled(PersonalAccountFeature::class)
			|| $user->isExtranet
			|| $user->isCollaber
			|| $user->isGuest
		)
		{
			return [
				'canUsePersonalAccount' => false,
				'companies' => [],
			];
		}

		$companies = (new SalaryVacationService())->getCompanyList($user->id)['companies'] ?? [];

		return [
			'canUsePersonalAccount' => !empty($companies),
			'companies' => $companies,
		];
	}

	/**
	 * Bitrix.Answers card visibility. The card needs no data yet, so the menu ships the flag only.
	 * The feature gate stays first, so a disabled feature costs nothing on top of the flag lookup.
	 */
	private function shouldShowBitrixAnswers(CommonUserDto $user): bool
	{
		if (Feature::isDisabled(BitrixAnswersFeature::class))
		{
			return false;
		}

		return !$user->isExtranet && !$user->isCollaber && !$user->isGuest;
	}

	private function getAhaMoment(): ?array
	{
		return (new AhaMoment())->getMenuAhaMoment();
	}

	public function getInitialMenuDataAction(): array
	{
		if (!Loader::includeModule('mobileapp'))
		{
			return [];
		}
		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$userId)
		{
			return [];
		}

		$user = $this->getUserData($userId);
		if (!$user)
		{
			return [];
		}

		$context = new Context([
			'userId' => $userId,
			'extranet' => $user->isExtranet,
			'isCollaber' => $user->isCollaber,
		]);

		$cache = new MenuListCache($context->userId);

		return [
			'restrictions' => [
				'canInvite' => $this->canInvite(),
				'canUseTimeMan' => $this->canUseTimeMan($context),
			],
			'ahaMoment' => (new AhaMoment())->getAvatarAhaMoment($userId),
			'menuList' => $user->isGuest ? [] : (new MenuList($context, $cache))->build(false),
		];
	}

}
