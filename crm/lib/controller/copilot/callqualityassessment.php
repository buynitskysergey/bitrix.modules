<?php

namespace Bitrix\Crm\Controller\Copilot;

use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum\Scenario as BizprocScenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Controller\Timeline\AI;
use Bitrix\Crm\Controller\Timeline\trait\ActivityLoader;
use Bitrix\Crm\Controller\Timeline\trait\ActivityPermissionsChecker;
use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Crm\Copilot\AiQualityAssessment\ViewModeEnum;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItemChecker;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\Pipeline\PipelineExecutor;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\ErrorCode as AIErrorCode;
use Bitrix\Crm\Integration\AI\Operation\OperationState;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\MultiValueStoreService;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Loader;
use CCrmOwnerType;

final class CallQualityAssessment extends Base
{
	use ActivityLoader;
	use ActivityPermissionsChecker;

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new Scope(Scope::NOT_REST);

		return $filters;
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				ItemIdentifier::class,
				'itemIdentifier',
				static function($className, $ownerTypeId, $ownerId) {
					return new ItemIdentifier($ownerTypeId, $ownerId);
				}
			),
		];
	}

	// region Actions

	/**
	 * 'crm.copilot.callqualityassessment.get' method handler.
	 *
	 * @param int $activityId
	 * @param int $assessmentSettingsId
	 * @param ItemIdentifier $itemIdentifier
	 *
	 * @return array|null
	 */
	public function getAction(
		int $activityId,
		int $assessmentSettingsId,
		ItemIdentifier $itemIdentifier
	): ?array
	{
		$activity = $this->loadActivity(
			$activityId,
			$itemIdentifier->getEntityTypeId(),
			$itemIdentifier->getEntityId()
		);
		if (!$activity)
		{
			return null;
		}

		$quality = AiQualityAssessmentController::getInstance()->getList([
			'filter' => [
				'ACTIVITY_ID' => $activityId,
				'ACTIVITY_TYPE' => AiQualityAssessmentTable::ACTIVITY_TYPE_CALL,
				'ASSESSMENT_SETTING_ID' => $assessmentSettingsId,
			],
			'limit' => 1,
		])->current();

		if ($quality)
		{
			return (new AI())->getCopilotCallQualityAction(
				$activityId,
				$itemIdentifier->getEntityTypeId(),
				$itemIdentifier->getEntityId(),
				$quality->getJobId(),
			);
		}

		$callAssessment = CopilotCallAssessmentController::getInstance()->getById($assessmentSettingsId);
		if ($callAssessment === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		$operationState = new OperationState($activityId, $itemIdentifier);
		$prevAssessmentAvg = AiQualityAssessmentController::getInstance()->getPrevAvgAssessmentValue($activity['RESPONSIBLE_ID']);

		return [
			'callQuality' => [
				'ID' => null,
				'CREATED_AT' => null,
				'ASSESSMENT_SETTING_ID' => $callAssessment->getId(),
				'ASSESSMENT_SETTINGS_STATUS' => $callAssessment->getStatus(),
				'ASSESSMENT' => null,
				'ASSESSMENT_AVG' => $prevAssessmentAvg,
				'PREV_ASSESSMENT_AVG' => $prevAssessmentAvg,
				'IS_PROMPT_CHANGED' => false,
				'USE_IN_RATING' => false,
				'PROMPT' => $callAssessment->getPrompt(),
				'ACTUAL_PROMPT' => $callAssessment->getPrompt(),
				'PROMPT_UPDATED_AT' => $callAssessment->getUpdatedAt(),
				'TITLE' => $callAssessment->getTitle(),
				'SUMMARY' => null,
				'RECOMMENDATIONS' => null,
			],
			'viewMode' => (
				$operationState->isCallScoringScenarioPending()
					? ViewModeEnum::pending->value
					: ViewModeEnum::usedNotAssessmentScript->value
			),
		];
	}

	/**
	 * 'crm.copilot.callqualityassessment.doAssessment' method handler.
	 *
	 * @param int $activityId
	 * @param int $assessmentSettingsId
	 * @param ItemIdentifier $itemIdentifier
	 *
	 * @return Result|null
	 */
	public function doAssessmentAction(
		int $activityId,
		int $assessmentSettingsId,
		ItemIdentifier $itemIdentifier
	): ?Result
	{
		$activity = $this->loadActivity(
			$activityId,
			$itemIdentifier->getEntityTypeId(),
			$itemIdentifier->getEntityId()
		);
		if (!$activity)
		{
			return null;
		}

		if (!$this->isUpdateEnable($itemIdentifier->getEntityTypeId(), $itemIdentifier->getEntityId()))
		{
			return null;
		}

		if (
			!AIManager::isAiCallProcessingEnabled()
			|| !in_array($itemIdentifier->getEntityTypeId(), AIManager::SUPPORTED_ENTITY_TYPE_IDS, true)
		)
		{
			$this->addError(AIErrorCode::getAIEngineNotFoundError());

			return null;
		}

		if (!AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment))
		{
			$this->addError(AIErrorCode::getAIDisabledError(['sliderCode' => Scenario::CALL_SCORING_SCENARIO_SLIDER_CODE]));

			return null;
		}

		$entity = CopilotCallAssessmentController::getInstance()->getById($assessmentSettingsId);
		if ($entity === null)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		$item = CallAssessmentItem::createFromEntity($entity);
		$checkerResult = CallAssessmentItemChecker::getInstance()
			->setItem($item)
			->run()
		;

		if (!$checkerResult->isSuccess())
		{
			$this->addError($checkerResult->getError());

			return null;
		}

		if (AIManager::isCallScoringV2Enabled())
		{
			return $this->fireCallAssessmentTrigger($activityId, $assessmentSettingsId);
		}

		return $this->launchLegacyCallScoring($activityId, $assessmentSettingsId);
	}
	// endregion

	private function getCurrentUserId(): int
	{
		return $this->getCurrentUser()?->getId() ?? Container::getInstance()->getContext()->getUserId();
	}

	private function fireCallAssessmentTrigger(int $activityId, int $assessmentSettingsId): ?Result
	{
		$userId = $this->getCurrentUserId();
		$target = new ItemIdentifier(CCrmOwnerType::Activity, $activityId);

		if (!Loader::includeModule('bizproc'))
		{
			$this->addError(AIErrorCode::getAIEngineNotFoundError());

			return (new Result(ScoreCallV2::TYPE_ID, $target, $userId, isPending: false))
				->addError(AIErrorCode::getAIEngineNotFoundError())
			;
		}

		$startResult = Starter::getByScenario(BizprocScenario::onEvent)
			->setContext(new ContextDto('crm'))
			->addEvent('CrmCallAssessmentTrigger', [], [
				'ActivityId' => $activityId,
				'AssessmentSettingsId' => $assessmentSettingsId,
				'UserId' => $userId,
			])
			->start()
		;

		if (!$startResult->isSuccess())
		{
			$this->addErrors($startResult->getErrors());

			return (new Result(ScoreCallV2::TYPE_ID, $target, $userId, isPending: false))
				->addErrors($startResult->getErrors())
			;
		}

		if (!$startResult->isTriggerApplied())
		{
			$this->addError(AIErrorCode::getAIEngineNotFoundError());

			return (new Result(ScoreCallV2::TYPE_ID, $target, $userId, isPending: false))
				->addError(AIErrorCode::getAIEngineNotFoundError())
			;
		}

		return new Result(ScoreCallV2::TYPE_ID, $target, $userId, isPending: true);
	}

	private function launchLegacyCallScoring(int $activityId, int $assessmentSettingsId): ?Result
	{
		$executor = ServiceLocator::getInstance()->get(PipelineExecutor::class);
		$context = new StepContext(
			activityId: $activityId,
			userId: $this->getCurrentUserId(),
			scenarioName: Scenario::CALL_SCORING_SCENARIO,
			isManualLaunch: true,
		);

		$result = $executor->startOrResume($context->withExtra('assessmentSettingsId', $assessmentSettingsId));

		if ($assessmentSettingsId > 0 && $result?->getJobId() !== null)
		{
			$key = ScoreCall::generateJobCallAssessmentBindKey($result->getJobId(), $activityId);
			MultiValueStoreService::getInstance()->set($key, $assessmentSettingsId);
		}

		if ($result?->isSuccess() === false)
		{
			$this->addErrors($result->getErrors());
		}

		return $result;
	}
}
