<?php

namespace Bitrix\Crm\Controller\Timeline;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Copilot\PullManager;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\DrawerRequest;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation\CallPresentationBuilder;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation\OpenLinePresentationBuilder;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation\PresentationResolver;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario\CallAssessmentScenarioBuilder;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario\ScenarioBuilderInterface;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario\ScenarioCode;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario\ScenarioRegistry;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario\SummaryHistoryScenarioBuilder;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ActivityBadgeCleaner;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ActivityContextLoader;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ClientDataProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ResponsibleDataProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SettingsProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SummaryDataProvider;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class AiReportDrawer extends Base
{
	protected function processBeforeAction(Action $action)
	{
		if ($this->hasInvalidParameter())
		{
			$this->addError(ErrorCode::getNotFoundError());

			return false;
		}

		return parent::processBeforeAction($action);
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();

		$filters[] = new ActionFilter\Scope(ActionFilter\Scope::NOT_REST);
		$filters[] = new class extends ActionFilter\Base {
			public function onBeforeAction(Event $event): ?EventResult
			{
				if (!AIManager::isAiCallProcessingEnabled())
				{
					$this->addError(ErrorCode::getAccessDeniedError());

					return new EventResult(EventResult::ERROR, null, 'crm', $this);
				}

				return null;
			}
		};

		return $filters;
	}

	public function loadCallAssessmentDrawerAction(
		int $activityId,
		int $ownerTypeId,
		int $ownerId,
		?int $jobId = null,
		?int $assessmentSettingsId = null,
	): ?array
	{
		$result = $this->loadScenario(
			ScenarioCode::CALL_ASSESSMENT,
			new DrawerRequest($activityId, $ownerTypeId, $ownerId, $jobId, $assessmentSettingsId),
			true,
		);
		if ($result === null)
		{
			$this->addError(ErrorCode::getNotFoundError());
		}

		return $result;
	}

	public function loadSummaryHistoryDrawerAction(
		int $activityId,
		int $ownerTypeId,
		int $ownerId,
		?int $jobId = null,
		?int $assessmentSettingsId = null,
	): ?array
	{
		$result = $this->loadScenario(
			ScenarioCode::SUMMARY_HISTORY,
			new DrawerRequest($activityId, $ownerTypeId, $ownerId, $jobId, $assessmentSettingsId),
			false,
		);
		if ($result === null)
		{
			$this->addError(ErrorCode::getNotFoundError());
		}

		return $result;
	}

	private function loadScenario(ScenarioCode $scenarioCode, DrawerRequest $request, bool $shouldSubscribeToPull): ?array
	{
		$currentUserId = (int)$this->getCurrentUser()?->getId();
		$contextResult = $this->createActivityContextLoader()->load($request, $currentUserId);
		if (!$contextResult->isSuccess())
		{
			$this->addErrors($contextResult->getErrors());

			return null;
		}

		$context = $contextResult->getData()['context'] ?? null;
		if (!$context instanceof ActivityContext)
		{
			$this->addError(new Error('Report drawer context not found'));

			return null;
		}

		if ($shouldSubscribeToPull)
		{
			$this->subscribeToPull($request->activityId, $currentUserId);
		}

		$scenarioBuilder = $this->createScenarioRegistry()->getByCode($scenarioCode);
		if (!$scenarioBuilder instanceof ScenarioBuilderInterface)
		{
			$this->addError(new Error('Report drawer scenario not found'));

			return null;
		}

		$result = $scenarioBuilder->build($context);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$drawerData = $result->getData()['drawerData'] ?? null;

		if (!is_array($drawerData))
		{
			$this->addError(new Error('Report drawer data not found'));

			return null;
		}

		return $drawerData;
	}

	private function subscribeToPull(int $activityId, int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		(new PullManager())->subscribe($userId, $activityId);
	}

	private function hasInvalidParameter(): bool
	{
		foreach (['activityId', 'ownerTypeId', 'ownerId', 'jobId', 'assessmentSettingsId'] as $parameterName)
		{
			$isFound = false;
			$value = $this->getSourceParameter($parameterName, $isFound);
			if ($isFound && $value !== null && !$this->isValidParameter($value))
			{
				return true;
			}
		}

		return false;
	}

	private function getSourceParameter(string $name, bool &$isFound): mixed
	{
		$isFound = false;

		foreach ($this->getSourceParametersList() as $source)
		{
			if (is_array($source) && array_key_exists($name, $source))
			{
				$isFound = true;

				return $source[$name];
			}

			if ($source instanceof \ArrayAccess && $source->offsetExists($name))
			{
				$isFound = true;

				return $source[$name];
			}
		}

		return null;
	}

	private function isValidParameter(mixed $value): bool
	{
		if (is_int($value))
		{
			return true;
		}

		if (!is_string($value))
		{
			return false;
		}

		if (!preg_match('/^([+-]?)(\d+)$/D', $value, $matches))
		{
			return false;
		}

		$digits = ltrim($matches[2], '0');
		$digits = $digits === '' ? '0' : $digits;
		$limit = $matches[1] === '-' ? ltrim((string)PHP_INT_MIN, '-') : (string)PHP_INT_MAX;

		return strlen($digits) < strlen($limit)
			|| (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0);
	}

	private function createActivityContextLoader(): ActivityContextLoader
	{
		$responsibleDataProvider = new ResponsibleDataProvider();

		return new ActivityContextLoader(
			new ClientDataProvider(),
			$responsibleDataProvider,
		);
	}

	private function createScenarioRegistry(): ScenarioRegistry
	{
		$responsibleDataProvider = new ResponsibleDataProvider();
		$presentationResolver = new PresentationResolver(
			new OpenLinePresentationBuilder($responsibleDataProvider),
			new CallPresentationBuilder(),
		);
		$jobRepository = JobRepository::getInstance();
		$summaryDataProvider = new SummaryDataProvider($jobRepository);
		$settingsProvider = new SettingsProvider();

		return new ScenarioRegistry(
			new CallAssessmentScenarioBuilder(
				$presentationResolver,
				$summaryDataProvider,
				$jobRepository,
				$settingsProvider,
			),
			new SummaryHistoryScenarioBuilder(
				$presentationResolver,
				$summaryDataProvider,
				new ActivityBadgeCleaner(),
				$settingsProvider,
			),
		);
	}
}
