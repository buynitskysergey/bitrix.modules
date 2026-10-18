<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\StaffTrack\Controller\Trait\ErrorResponseTrait;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Provider\ChatProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInFormProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInProvider;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;
use Bitrix\StaffTrack\Public\Provider\GeoProvider;
use Bitrix\StaffTrack\Public\Services\AddressResolverService;
use Bitrix\StaffTrack\Public\Services\CheckInService;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\CheckInToolEnabled;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\CheckInAvailableByRegionFilter;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\IntranetOnlyFilter;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\NewCheckInEnabledFilter;

final class CheckIn extends Base
{
	use ErrorResponseTrait;

	public function configureActions(): array
	{
		return [];
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new IntranetOnlyFilter(),
			new NewCheckInEnabledFilter(),
			new CheckInAvailableByRegionFilter(),
			new CloseSession(),
		];
	}

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				CheckInDto::class,
				'checkInDto',
				function($className, $checkInDto) {
					return CheckInDto::createFromArray((array)$checkInDto);
				},
			),
		];
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.add
	 * @param CheckInDto $checkInDto
	 * @return array|null
	 */
	#[CheckInToolEnabled]
	public function addAction(CheckInDto $checkInDto, array $uploadedFiles = []): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$checkInDto->setUserId($userId);

		$timeZone = \CTimeZone::GetOffset();
		$checkInDto->setUserTimezone($timeZone);

		$result = CheckInService::getInstance()->add($checkInDto, $uploadedFiles);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.batchAdd
	 * @param array $checkIns
	 * @return array|null
	 */
	#[CheckInToolEnabled]
	public function batchAddAction(array $checkIns): ?array
	{
		if (count($checkIns) > CheckInService::MAX_BATCH_SIZE)
		{
			$this->addErrors([
				new \Bitrix\Main\Error('Check-in batch size exceeds the limit of ' . CheckInService::MAX_BATCH_SIZE),
			]);

			return null;
		}

		$userId = (int)$this->getCurrentUser()?->getId();
		$timeZone = \CTimeZone::GetOffset();

		$dtos = [];
		foreach ($checkIns as $point)
		{
			$dto = CheckInDto::createFromArray((array)$point);
			$dto->setUserId($userId);
			$dto->setUserTimezone($timeZone);

			$dtos[] = $dto;
		}

		$result = CheckInService::getInstance()->addBatch($dtos);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.createChatWithDepartmentHead
	 * @return array
	 */
	public function createChatWithDepartmentHeadAction(): array
	{
		$chatCreateResult = (new ChatProvider())->createDepartmentHeadChat();

		if (!$chatCreateResult->isSuccess())
		{
			$this->addErrors($chatCreateResult->getErrors());

			return [];
		}

		return $chatCreateResult->getData();
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getUserCheckInByDate
	 * @param int $userId
	 * @param int $timestamp
	 * @return array
	 */
	#[CheckInToolEnabled]
	public function getUserCheckInByDateAction(int $userId, int $timestamp): ?array
	{
		if (!$this->canViewEmployee($userId))
		{
			return null;
		}

		$timezoneOffset = \CTimeZone::GetOffset();
		$result = (new CheckInProvider())->getUserCheckInByDate($userId, $timestamp, $timezoneOffset);
		$result['users'] = UserRepository::getByIds([$userId]);

		$this->enqueueTimelineAddressResolve($result, $userId);

		return $result;
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getMapData
	 * @param int $timestamp
	 * @return array
	 */
	#[CheckInToolEnabled]
	public function getMapDataAction(int $timestamp = 0): array
	{
		$provider = new CheckInProvider();
		$timezoneOffset = \CTimeZone::GetOffset();
		$userId = (int)$this->getCurrentUser()?->getId();

		['checkIns' => $checkIns, 'stats' => $stats, 'manualStats' => $manualStats, 'checkInRolloutEnabled' => $checkInRolloutEnabled] = $provider->getLatestUsersCheckInByDepartment($timezoneOffset, $timestamp);

		return [
			'checkIns' => $checkIns,
			'stats' => $stats,
			'manualStats' => $manualStats,
			'checkInRolloutEnabled' => $checkInRolloutEnabled,
			'hasSubordinates' => DepartmentProvider::hasSubordinates($userId),
			'users' => UserRepository::getByIds(array_column($checkIns, 'userId')),
			'isWesternPortal' => Feature::isGeoRetentionLimited(),
		];
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getEmployeeRoute
	 * @param int $userId
	 * @param int $timestamp
	 * @return array
	 */
	#[CheckInToolEnabled]
	public function getEmployeeRouteAction(int $userId, int $timestamp): ?array
	{
		if (!$this->canViewEmployee($userId))
		{
			return null;
		}

		$timezoneOffset = \CTimeZone::GetOffset();

		return (new CheckInProvider())->getEmployeeRoute($userId, $timestamp, $timezoneOffset);
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.loadCheckInForm
	 * @param float $latitude
	 * @param float $longitude
	 * @return array|null
	 */
	public function loadCheckInFormAction(float $latitude = 0, float $longitude = 0): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();

		$formProvider = new CheckInFormProvider();

		$needGeo = ($latitude !== 0.0 && $longitude !== 0.0);
		$result = $needGeo
			? $formProvider->load($userId, $latitude, $longitude)
			: $formProvider->load($userId);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();
		$data['users'] = UserRepository::getByIds([$userId]);

		return $data;
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getManualCheckInCount
	 * @return array
	 */
	public function getManualCheckInCountAction(): array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$timezoneOffset = \CTimeZone::GetOffset();

		$count = (new CheckInProvider())
			->getManualCheckInCountByDate($userId, time(), $timezoneOffset)
		;

		return ['count' => $count];
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.getAddressByCoordinates
	 * @param float $latitude
	 * @param float $longitude
	 * @return array
	 */
	#[CheckInToolEnabled]
	public function getAddressByCoordinatesAction(float $latitude, float $longitude): array
	{
		$geoProvider = new GeoProvider($latitude, $longitude);
		$result = $geoProvider->getAddress();
		$address = $result->isSuccess() ? ($result->getData()['address'] ?? '') : '';

		return ['address' => $address];
	}

	/**
	 * In v3 ordinary timeline rows get their address through `markers`, but the ends of the route stand
	 * on technical moving points, which never appear in `markers` - those two come from the track. The
	 * v2 timeline shows every point of the day as its own row, so for a v2 viewer the whole flat list
	 * goes to the queue. Points that already have an address are dropped by AddressResolverService::enqueue().
	 *
	 * @param array{markers?: array<int, array>, track?: array<int, array>, checkIns?: array<int, array>} $dayResult
	 */
	private function enqueueTimelineAddressResolve(array $dayResult, int $userId): void
	{
		$viewerId = (int)$this->getCurrentUser()?->getId();

		// the provider answers without markers or track when it has no viewer (CheckInProvider::getUserCheckInByDate)
		$points = Feature::isCheckInRolloutEnabled($viewerId)
			? array_merge(
				$dayResult['markers'] ?? [],
				self::collectRouteEnds($dayResult['track'] ?? [], $userId),
			)
			: ($dayResult['checkIns'] ?? []);
		if (empty($points))
		{
			return;
		}

		Application::getInstance()->addBackgroundJob(
			static fn() => (new AddressResolverService())->enqueue($points, $viewerId),
		);
	}

	/**
	 * @param array<int, array{id: int, time: int, address: string}> $track
	 * @return array<int, array>
	 */
	private static function collectRouteEnds(array $track, int $userId): array
	{
		if (empty($track))
		{
			return [];
		}

		$ends = [$track[array_key_first($track)]];
		if (count($track) > 1)
		{
			$ends[] = $track[array_key_last($track)];
		}

		return array_map(
			static fn(array $point): array => $point + [
				'userId' => $userId,
				'dateCreate' => date('Y-m-d H:i:s', (int)($point['time'] ?? 0)),
			],
			$ends,
		);
	}

	private function canViewEmployee(int $targetUserId): bool
	{
		$currentUserId = (int)$this->getCurrentUser()?->getId();
		if ($currentUserId === $targetUserId)
		{
			return true;
		}

		if (DepartmentProvider::isMySubordinate($targetUserId))
		{
			return true;
		}

		$this->addError(new \Bitrix\Main\Error('Access denied', 403));

		return false;
	}
}
