<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Application;
use Bitrix\StaffTrack\Infrastructure\Agent\CheckIn\AddressResolveAgent;
use Bitrix\StaffTrack\Internal\Geo\Geohash;
use Bitrix\StaffTrack\Internal\Repository\AddressQueueRepository;

class AddressResolverService
{
	private const FALLBACK_AGENT_DELAY = 30;

	public function enqueue(array $checkIns, int $viewerId = 0): void
	{
		try
		{
			$this->processEnqueue($checkIns, $viewerId);
		}
		catch (\Throwable $exception)
		{
			// the only caller is a background job of a request already answered, so the failure has
			// nowhere to be reported to: without this record the points stay addressless silently
			AddMessage2Log(
				'AddressResolverService::enqueue error: '
				. $exception->getMessage() . "\n" . $exception->getTraceAsString(),
			);
		}
	}

	private function processEnqueue(array $checkIns, int $viewerId = 0): void
	{
		$added = false;

		foreach ($checkIns as $checkIn)
		{
			$latitude = (float)($checkIn['latitude'] ?? $checkIn['lat'] ?? 0);
			$longitude = (float)($checkIn['longitude'] ?? $checkIn['lon'] ?? 0);

			if (!empty($checkIn['address']) || ($latitude == 0 && $longitude == 0))
			{
				continue;
			}

			$checkInId = (int)($checkIn['id'] ?? 0);
			$userId = (int)($checkIn['userId'] ?? 0);
			$dateCreate = $checkIn['dateCreate'] ?? '';

			if ($checkInId === 0 || $userId === 0 || $dateCreate === '')
			{
				continue;
			}

			$geohash = Geohash::encode($latitude, $longitude);
			$dateCreatePrepared = new \Bitrix\Main\Type\DateTime($dateCreate, 'Y-m-d H:i:s');

			if (AddressQueueRepository::add($checkInId, $geohash, $userId, $dateCreatePrepared, $viewerId))
			{
				$added = true;
			}
		}

		if (!$added)
		{
			return;
		}

		$this->scheduleAgent();
		$this->tryProcessImmediately();
	}

	private function tryProcessImmediately(): bool
	{
		$connection = Application::getConnection();

		if (!$connection->lock(AddressResolveAgent::QUEUE_LOCK_NAME, 0))
		{
			return false;
		}

		try
		{
			return AddressResolveAgent::runOnceLocked();
		}
		finally
		{
			$connection->unlock(AddressResolveAgent::QUEUE_LOCK_NAME);
		}
	}

	private function scheduleAgent(): void
	{
		$agentName = 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\AddressResolveAgent::run();';

		$existing = \CAgent::GetList(
			[],
			[
				'MODULE_ID' => 'stafftrack',
				'NAME' => $agentName,
			],
		)->Fetch();

		if ($existing)
		{
			return;
		}

		\CAgent::AddAgent(
			$agentName,
			'stafftrack',
			'N',
			0,
			'',
			'Y',
			ConvertTimeStamp(time() + self::FALLBACK_AGENT_DELAY, 'FULL'),
		);
	}
}
