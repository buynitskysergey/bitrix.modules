<?php

declare(strict_types=1);

namespace Bitrix\Crm\Controller\Copilot;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Copilot\CallAssessment\Summary\Settings;
use Bitrix\Crm\Copilot\CallAssessment\Summary\SettingsRepository;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\ActionFilter\Scope;

final class CallAssessmentSummary extends Base
{
	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new Scope(Scope::NOT_REST);

		return $filters;
	}

	public function saveSettingsAction(array $settings): ?array
	{
		if (!$this->checkAccess())
		{
			return null;
		}

		$normalized = Settings::fromArray($settings);
		(new SettingsRepository())->save($normalized);

		return $normalized->toArray();
	}

	private function checkAccess(): bool
	{
		if (
			!Bitrix24Manager::isFeatureEnabled(AIManager::AI_COPILOT_FEATURE_NAME)
			|| !AIManager::isAiCallProcessingEnabled()
			|| !AIManager::isCallScoringV2Enabled()
			|| !Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canEdit()
		)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return false;
		}

		return true;
	}
}
