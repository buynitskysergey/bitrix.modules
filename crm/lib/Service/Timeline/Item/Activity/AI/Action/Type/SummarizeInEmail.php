<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type;

use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIAction;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIOperationStateChecker;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\OpenLineScenarioAvailability;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\StateChecker\ResultStateChecker;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

final class SummarizeInEmail extends AIAction
{
	public static function getScenario(): string
	{
		return Scenario::SUMMARIZE_SCENARIO;
	}

	public static function getSupportedProviders(): array
	{
		return [
			Email::getId(),
		];
	}

	protected function getName(): string
	{
		return Loc::getMessage('CRM_TIMELINE_AI_EMAIL_SUMMARIZE') ?? 'Summarize';
	}

	protected function getEventName(): string
	{
		return 'Email:LaunchCopilot';
	}

	protected function createStateChecker(): ?AIOperationStateChecker
	{
		$identifier = $this->context->getIdentifier();
		$result = JobRepository::getInstance()->getSummarizeCallTranscriptionResultByActivity(
			$this->rootActivityId,
			null,
			$identifier->getEntityTypeId(),
			$identifier->getEntityId(),
		);

		return $result !== null ? new ResultStateChecker($result) : null;
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
		return $jsEvent->addActionParamString('scenario', Scenario::SUMMARIZE_SCENARIO);
	}

	protected function getMenuIcon(): Outline
	{
		return Outline::SIGMA_SUMM;
	}
}
