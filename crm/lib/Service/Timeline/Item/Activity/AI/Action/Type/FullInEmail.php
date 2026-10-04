<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\OpenLineScenarioAvailability;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker\ScenarioStateChecker;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class FullInEmail extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::FULL_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			Email::getId(),
		];
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_EMAIL_FULL') ?? 'Full processing';
	}

	protected function getEventName(): string
	{
		return 'Email:LaunchCopilot';
	}

	protected function createStateChecker(): ?AIOperationStateChecker
	{
		$state = new OperationState($this->rootActivityId, $this->context->getIdentifier());

		return new ScenarioStateChecker(
			$state,
			fn(OperationState $state) => $state->isFullChatScenarioPending(),
			fn(OperationState $state) => $state->isFullChatScenarioSuccess(),
			fn(OperationState $state) => $state->isFullChatScenarioErrorsLimitExceeded(),
		);
	}

	protected function isDisabled(): bool
	{
		$stateChecker = $this->getStateChecker();
		$isSuccess = $stateChecker?->isSuccess() ?? false;

		return OpenLineScenarioAvailability::isDisabled(
			true,
			$isSuccess && $this->isRepeatRunAvailable(),
			$isSuccess,
			$stateChecker?->isPending() ?? false,
			$stateChecker?->isErrorsLimitExceeded() ?? false,
		);
	}

	public function isHidden(): bool
	{
		if (!Scenario::isManualFullScenarioAvailable(Email::getId()))
		{
			return true;
		}

		return $this->isDisabled();
	}

	private function isRepeatRunAvailable(): bool
	{
		$identifier = $this->context->getIdentifier();

		return Email::isCopilotRepeatProcessingAvailable(
			$this->rootActivityId,
			$identifier->getEntityTypeId(),
			$identifier->getEntityId(),
		);
	}

	public function isMenuOnly(): bool
	{
		return true;
	}

	protected function addCustomParams(JsEvent $jsEvent): JsEvent
	{
		return $jsEvent->addActionParamString('scenario', Scenario::FULL_SCENARIO);
	}

	protected function getMenuIcon(): Outline
	{
		return Outline::AI_STARS;
	}
}
