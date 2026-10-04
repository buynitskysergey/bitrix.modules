<?php

namespace Bitrix\Crm\Tour;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Tour\Mixin\HasEntitySupport;
use Bitrix\Main\Localization\Loc;

final class CopilotInEmail extends Base
{
	use HasEntitySupport;

	protected const OPTION_NAME = 'copilot-in-email';

	protected function canShow(): bool
	{
		if (!$this->isShowEnabled())
		{
			return false;
		}

		if ($this->isUserSeenTour())
		{
			return false;
		}

		return true;
	}

	protected function getSteps(): array
	{
		return [
			[
				'id' => self::OPTION_NAME,
				'title' => Loc::getMessage(
					'CRM_TOUR_COPILOT_IN_EMAIL_TITLE',
					['#COPILOT_NAME#' => AIManager::getCopilotName()]
				),
				'text' => Loc::getMessage(
					'CRM_TOUR_COPILOT_IN_EMAIL_TEXT',
					['#COPILOT_NAME#' => AIManager::getCopilotName()]
				),
				'position' => 'top',
				'useDynamicTarget' => true,
				'eventName' => 'BX.Crm.Timeline.Email:onShowCopilotTour',
				'article' => 18799442,
			],
		];
	}

	protected function getOptions(): array
	{
		return [
			'hideTourOnMissClick' => true,
			'steps' => [
				'popup' => [
					'width' => 400,
				],
			],
		];
	}

	protected function isShowEnabled(): bool
	{
		return AIManager::isAiCallProcessingEnabled()
			&& in_array(
				$this->entityTypeId,
				AIManager::SUPPORTED_ENTITY_TYPE_IDS,
				true
			)
		;
	}
}
