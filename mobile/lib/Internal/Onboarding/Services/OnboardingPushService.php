<?php

namespace Bitrix\Mobile\Internal\Onboarding\Services;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Internal\Onboarding\Dto\LastPushInfo;

class OnboardingPushService
{
	private const OPTION_CATEGORY = 'mobile';
	private const LAST_PUSH_OPTION = 'OnboardingLastPush';

	public function getLastSentPush(int $userId): ?LastPushInfo
	{
		$value = \CUserOptions::GetOption(self::OPTION_CATEGORY, self::LAST_PUSH_OPTION, null, $userId);
		if (empty($value))
		{
			return null;
		}

		try
		{
			$data = Json::decode($value);
		}
		catch (\Exception)
		{
			return null;
		}

		if (!is_array($data) || empty($data['type']) || empty($data['timestamp']))
		{
			return null;
		}

		return new LastPushInfo(
			type: (string)$data['type'],
			timestamp: (int)$data['timestamp'],
		);
	}

	public function setLastSentPush(int $userId, string $type, ?int $timestamp = null): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$timestamp ??= (new DateTime())->getTimestamp();
		$data = ['type' => $type, 'timestamp' => $timestamp];

		\CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			self::LAST_PUSH_OPTION,
			Json::encode($data),
			false,
			$userId,
		);
	}
}
