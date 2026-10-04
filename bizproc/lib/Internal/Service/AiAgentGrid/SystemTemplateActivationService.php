<?php

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid;

use Bitrix\Bizproc\Internal\Event\SetupTemplateCurrentDataEvent;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\AiAgentStartResult;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ORM\Data\UpdateResult;

use Bitrix\Ai\Integration\Bizproc\Event\Enum\ProcessedEvent;
use Bitrix\Ai\Integration\Bizproc\Event\Payload\AiListenerParameters;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Enum\Template\CreateSource;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Result\TemplateCreatedResult;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\TemplateRevisionService;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Event;
use Bitrix\Bizproc\Starter\Result\StartResult;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;

class SystemTemplateActivationService
{
	private const AI_AGENT_START_TRIGGER = 'AiAgentStartTrigger';
	private const AI_AGENT_RESTART_TRIGGER = 'AiAgentRestartTrigger';

	private const RESTART_FALLBACK_ACTION_NAME = 'RESTART_FALLBACK_TO_START';

	public function __construct(
		private readonly AiAgentRepository $aiAgentRepository,
		private readonly TemplateRevisionService $templateRevisionService = new TemplateRevisionService(),
	) {}

	public function includeModuleAi(Result $result = new Result()): Result
	{
		if (!Loader::includeModule('ai'))
		{
			$result->addError(
				\Bitrix\Bizproc\Error::fromCode(
					\Bitrix\Bizproc\Error::MODULE_NOT_INSTALLED,
					['moduleName' => 'ai'],
				)
			);
		}

		return $result;
	}

	/**
	 * Whether the installed ai module ships the restart contract. bizproc can be updated ahead of
	 * ai (the modules release independently): the older AiListenerParameters class still exists but
	 * has no restart fields, so touching a restart-only symbol would fatal. When this is false the
	 * service degrades to the plain start branch and never references a restart symbol.
	 */
	private function isRestartContractAvailable(): bool
	{
		return defined(AiListenerParameters::class . '::RUN_TYPE_RESTART');
	}

	public function copyTemplate(
		int $templateId,
		int $userId,
		CreateSource $source = CreateSource::User,
	): Result|TemplateCreatedResult
	{
		$copier = new \Bitrix\Bizproc\Copy\Implement\WorkflowTemplate();
		$fields = $copier->getFields($templateId);
		if (empty($fields))
		{
			return (new Result())->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getError(['#ID#' => $templateId]));
		}

		$fields = $copier->prepareFieldsToCopy($fields);

		$originSystemCode = $fields['SYSTEM_CODE'] ?? null;

		$fields['USER_ID'] = $userId;
		$fields['IS_SYSTEM'] = 'N';
		$fields['ACTIVE'] = 'Y';
		$fields['SYSTEM_CODE'] = null;
		$fields['ACTIVATED_BY'] = $userId;
		$fields['ACTIVATED_AT'] = new DateTime();
		$fields['CREATE_SOURCE'] = $source->value;

		$newTemplateId = (int)$copier->add($fields);
		if ($newTemplateId <= 0)
		{
			return (new Result())->addError(ErrorMessage::CREATE_WORKFLOW->getError());
		}

		if ($originSystemCode !== null)
		{
			$this->aiAgentRepository->saveOriginSystemCode($newTemplateId, $originSystemCode);

			$originSystemVersion = $this->templateRevisionService->getTemplateRevision($templateId);
			if ($originSystemVersion !== null)
			{
				$this->aiAgentRepository->saveOriginSystemVersion($newTemplateId, $originSystemVersion);
			}
		}

		$result = new TemplateCreatedResult($newTemplateId);

		$fields['ID'] = $newTemplateId;

		$resultData = [
			'rawTemplateFields' => $fields,
		];

		$result->setData($resultData);

		return $result;
	}

	/**
	 * @param bool $asRestart Restart entry point (the grid "Restart" action). Only then may the
	 *   dedicated restart branch be selected — a first launch (copyAndStart) and the post-upgrade
	 *   onboarding re-launch keep the start branch regardless of any registered restart trigger.
	 * @param array $startParameters Start parameters of the template itself, unlike the trigger event
	 *   payload: they reach the process as its own top level parameters. Left empty the launch is
	 *   identical to one without them: a parameter the caller omits keeps its template default.
	 */
	public function startTemplate(
		int $templateId,
		?int $userId = null,
		bool $asRestart = false,
		array $startParameters = [],
	): AiAgentStartResult
	{
		$includeResult = $this->includeModuleAi();

		if (
			!$includeResult->isSuccess()
			|| !class_exists(AiListenerParameters::class)
		)
		{
			return (new AiAgentStartResult())
				->addErrors($includeResult->getErrors())
			;
		}

		$userId ??= (int)CurrentUser::get()->getId();
		$setupTemplateDataEvent = null;
		$eventHandler = EventManager::getInstance()
			->addEventHandler(
			  fromModuleId: SetupTemplateCurrentDataEvent::MODULE_ID,
			  eventType: SetupTemplateCurrentDataEvent::EVENT_NAME,
			  callback: function(SetupTemplateCurrentDataEvent $event) use ($userId, $templateId, &$setupTemplateDataEvent)
				{
					if ($event->getTemplateId() === $templateId && $event->getUserId() === $userId && $event->getBlocks())
					{
					  $setupTemplateDataEvent = $event;
					}
				}
			)
		;

		$parameters = $this->buildListenerParameters($templateId, $userId, $asRestart);
		$isRestartRun = $this->isRestartRun($parameters);
		$triggerCode = $isRestartRun ? self::AI_AGENT_RESTART_TRIGGER : self::AI_AGENT_START_TRIGGER;

		$startResult = $this->handleOnAiAgentStart($parameters, $triggerCode, $startParameters);

		EventManager::getInstance()
			->removeEventHandler(
				fromModuleId: SetupTemplateCurrentDataEvent::MODULE_ID,
				eventType: SetupTemplateCurrentDataEvent::EVENT_NAME,
				iEventHandlerKey: $eventHandler,
			)
		;

		if ($asRestart && !$isRestartRun)
		{
			// A restart was requested but the template has no restart trigger, so the launch was
			// routed through the start branch (fallback). Note it on the started run (AC-013). This
			// only fires for the restart entry point, never for a genuine first launch.
			$this->journalRestartFallbackToStart(
				(string)($startResult->getTemplateWorkflowIds()[$templateId] ?? ''),
				$parameters->startedBy,
			);
		}

		if (
			!empty($startResult->getTemplateWorkflowIds()[$templateId])
			&& $startResult->isTriggerApplied()
		)
		{
			$this->markAsActivatedNow($templateId);

			return (new AiAgentStartResult($setupTemplateDataEvent))
				->setData($this->decorateWithRunInfo($startResult->getData(), $parameters, $isRestartRun))
			;
		}

		return (new AiAgentStartResult($setupTemplateDataEvent))
			->addErrors($startResult->getErrors())
		;
	}

	/**
	 * Builds the server-side listener payload and, with it, selects the branch to publish: the
	 * dedicated restart branch when the template registers a restart trigger, otherwise the
	 * current start branch (natural fallback for templates without one). The branch is decided
	 * here, before execution — the classifier never overrides it — and once the restart branch
	 * is chosen a failed start is never replayed as a start (see startTemplate).
	 */
	private function buildListenerParameters(int $templateId, int $userId, bool $asRestart): AiListenerParameters
	{
		if ($asRestart && $this->isRestartContractAvailable() && $this->hasRestartTrigger($templateId))
		{
			return $this->buildRestartPayload($templateId, $userId);
		}

		return new AiListenerParameters(
			event: new Event(name: ProcessedEvent::OnAiAgentStart->name),
			templateId: $templateId,
			startedBy: $userId,
		);
	}

	private function isRestartRun(AiListenerParameters $parameters): bool
	{
		// The && short-circuits before the restart constant is resolved, so an older ai without the
		// restart contract can never reach and fatal on it.
		return $this->isRestartContractAvailable()
			&& $parameters->runType === AiListenerParameters::RUN_TYPE_RESTART
		;
	}

	/**
	 * Whether the template has a registered restart trigger (a b_bp_workflow_template_trigger row
	 * with TRIGGER_TYPE = AiAgentRestartTrigger). This is the single branch discriminant used
	 * before start.
	 */
	public function hasRestartTrigger(int $templateId): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		return (bool)WorkflowTemplateTriggerTable::query()
			->setSelect(['TEMPLATE_ID'])
			->where('TEMPLATE_ID', $templateId)
			->where('TRIGGER_TYPE', self::AI_AGENT_RESTART_TRIGGER)
			->setLimit(1)
			->fetch()
		;
	}

	/**
	 * Server-side EVENT-01 restart payload. Every field is derived from server state, never from
	 * the request (IDOR guard): restartRunId is a freshly pre-generated, unique run id (so repeated
	 * restart clicks never collapse into one), previousRunId/agentInstanceId are the last run of
	 * this template resolved from durable workflow state (so an already finished run still yields a
	 * non-empty id, keeping the audit chain), initiatorId is the acting user and timestamp is the
	 * server clock.
	 */
	public function buildRestartPayload(int $templateId, int $userId): AiListenerParameters
	{
		$currentInstanceId = $this->resolveLatestInstanceId($templateId);

		return new AiListenerParameters(
			event: new Event(name: ProcessedEvent::OnAiAgentRestart->name),
			templateId: $templateId,
			startedBy: $userId,
			agentInstanceId: $currentInstanceId,
			restartRunId: \CBPRuntime::generateWorkflowId(),
			previousRunId: $currentInstanceId,
			initiatorId: $userId,
			timestamp: time(),
			runType: AiListenerParameters::RUN_TYPE_RESTART,
		);
	}

	/**
	 * Id of the last run of the template, newest by start time. Resolved from b_bp_workflow_state,
	 * which outlives workflow completion (b_bp_workflow_instance rows are dropped once a run finishes,
	 * so they would resolve to '' for the main restart case of an already finished agent). The state
	 * id equals the run/instance id, and the query rides the (WORKFLOW_TEMPLATE_ID, STARTED) index.
	 */
	private function resolveLatestInstanceId(int $templateId): string
	{
		if ($templateId <= 0)
		{
			return '';
		}

		$row = WorkflowStateTable::query()
			->setSelect(['ID'])
			->where('WORKFLOW_TEMPLATE_ID', $templateId)
			// STARTED has second granularity, so several quick launches can share a value; ID
			// (uniqid, microsecond-ordered) is the deterministic tie-break for the newest run.
			->setOrder(['STARTED' => 'DESC', 'ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return (string)($row['ID'] ?? '');
	}

	private function decorateWithRunInfo(array $data, AiListenerParameters $parameters, bool $isRestartRun): array
	{
		if (!$this->isRestartContractAvailable())
		{
			return $data;
		}

		$data[AiListenerParameters::KEY_RUN_TYPE] = $parameters->runType;

		if ($isRestartRun)
		{
			$data[AiListenerParameters::KEY_RESTART_RUN_ID] = $parameters->restartRunId;
		}

		return $data;
	}

	/***
	 * @param AiListenerParameters $parameters
	 * @param string $triggerCode Trigger code to publish; branches runEvent() by TRIGGER_TYPE.
	 * @param array $startParameters Start parameters of the template, see startTemplate().
	 *
	 * @return StartResult
	 */
	private function handleOnAiAgentStart(
		AiListenerParameters $parameters,
		string $triggerCode,
		array $startParameters,
	): StartResult
	{
		$document = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexId((string)$parameters->templateId);
		$documentType = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		$documentDto = new DocumentDto($document, $documentType);

		$starter = Starter::getByScenario(Scenario::onEvent)
			->addEvent(
				code: $triggerCode,
				documents: [$documentDto],
				parameters: $parameters->toArray(),
				userId: $parameters->startedBy,
			)
			->setTemplateIds([$parameters->templateId])
		;

		if ($startParameters !== [])
		{
			$starter->setParameters([$parameters->templateId => $startParameters]);
		}

		return $starter->start();
	}


	private function markAsActivatedNow(int $templateId): UpdateResult
	{
		$nowDateTime = new DateTime();
		return $this->aiAgentRepository->updateActivationTimestamp($templateId, $nowDateTime);
	}

	/**
	 * Journals into b_bp_tracking that a restart request was handled by the start branch because the
	 * template has no restart trigger (AC-013). Addressed by the started run and attributed to the
	 * initiator; forced so the note is kept even where tracking is otherwise off (RestrictedTracking).
	 */
	private function journalRestartFallbackToStart(string $workflowId, int $userId): void
	{
		if ($workflowId === '')
		{
			return;
		}

		$trackingService = \CBPRuntime::getRuntime()->getTrackingService();
		$trackingService->setForcedMode($workflowId);
		$trackingService->write(
			$workflowId,
			\CBPTrackingType::Trigger,
			self::RESTART_FALLBACK_ACTION_NAME,
			\CBPActivityExecutionStatus::Closed,
			\CBPActivityExecutionResult::Succeeded,
			'',
			'restart handled by start branch: no restart trigger registered',
			$userId,
		);
	}
}