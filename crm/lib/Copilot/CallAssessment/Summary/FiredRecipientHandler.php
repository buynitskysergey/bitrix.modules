<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Intranet\Entity\User;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;

final class FiredRecipientHandler
{
	public static function onAfterUserFire(Event $event): EventResult
	{
		if (!AIManager::isCallScoringV2Enabled())
		{
			return new EventResult(EventResult::SUCCESS);
		}

		if (!Loader::includeModule('intranet'))
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$user = $event->getParameter('user');
		if (!$user instanceof User)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$userId = (int)$user->getId();
		if ($userId <= 0)
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$repo = new SettingsRepository();
		if (!$repo->isInitialized())
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$settings = $repo->load();
		if (!in_array($userId, $settings->recipientUserIds, true))
		{
			return new EventResult(EventResult::SUCCESS);
		}

		$filtered = array_values(array_filter(
			$settings->recipientUserIds,
			static fn(int $id) => $id !== $userId,
		));

		$repo->save($settings->withRecipientUserIds($filtered));

		return new EventResult(EventResult::SUCCESS);
	}
}
