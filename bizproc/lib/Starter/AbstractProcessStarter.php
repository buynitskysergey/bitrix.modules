<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Activity\Mixins\ApplyRulesChecker;
use Bitrix\Bizproc\Api\Enum\Template\CreateSource;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;
use Bitrix\Crm\Automation\Trigger\BaseTrigger;
use CBPCrmAutomationTrigger;

abstract class AbstractProcessStarter extends BaseTypeStarter
{
	use ApplyRulesChecker;

	// EVENT-01 restart payload keys. The AI agent restart contract is owned by the ai module;
	// bizproc must not depend on ai, so the keys are mirrored here as literals.
	private const RESTART_PARAM_RUN_TYPE = 'runType';
	private const RESTART_PARAM_RUN_ID = 'restartRunId';
	private const RESTART_PARAM_PREVIOUS_RUN_ID = 'previousRunId';
	private const RESTART_PARAM_INITIATOR_ID = 'initiatorId';
	private const RUN_TYPE_RESTART = 'restart';

	// Trigger code (TRIGGER_TYPE) of the dedicated AI agent restart trigger. runEvent() is the shared
	// onEvent start path for every workflow, so restart semantics are bound to this code and never to
	// the mere presence of restart keys in the payload — a look-alike payload on any other trigger
	// must not be routed through the restart branch (defense-in-depth).
	private const RESTART_TRIGGER_CODE = 'AiAgentRestartTrigger';

	private const RESTART_TRACKING_ACTION_NAME = 'APPLIED_RESTART_TRIGGER';

	abstract protected function getCreateSourceFilter(): CreateSource;

	protected function checkFeature(?ModuleSettings $moduleSettings = null): bool
	{
		return \CBPRuntime::isFeatureEnabled();
	}

	protected function isOverLimited(?ModuleSettings $moduleSettings = null): bool
	{
		return false;
	}

	protected function runRestScenario(): bool
	{
		$startParameters = array_merge(
			[
				\CBPDocument::PARAM_TAGRET_USER => $this->getTargetUserForStartParameters(),
			],
			$this->getManualStartSurfaceForStartParameters($this->userId),
		);

		return $this->runMultiWorkflows($startParameters);
	}

	protected function runManualScenario(): bool
	{
		$startParameters = array_merge(
			[
				\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => \CBPDocumentEventType::Manual,
				\CBPDocument::PARAM_TAGRET_USER => $this->getTargetUserForStartParameters(),
				\CBPDocument::PARAM_MODIFIED_DOCUMENT_FIELDS => false,
				\CBPDocument::PARAM_DOCUMENT_TYPE => $this->getDocumentTypeForStartParameters(),
			],
			$this->getManualStartSurfaceForStartParameters($this->userId),
		);

		return $this->runMultiWorkflows($startParameters);
	}

	protected function runOnAddScenario(): bool
	{
		$startParameters = [
			\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => \CBPDocumentEventType::Create,
			\CBPDocument::PARAM_TAGRET_USER => $this->getTargetUserForStartParameters(),
			\CBPDocument::PARAM_MODIFIED_DOCUMENT_FIELDS => false,
			\CBPDocument::PARAM_DOCUMENT_TYPE => $this->getDocumentTypeForStartParameters(),
		];

		return $this->runMultiWorkflows($startParameters);
	}

	protected function runOnUpdateScenario(): bool
	{
		$startParameters = [
			\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => \CBPDocumentEventType::Edit,
			\CBPDocument::PARAM_TAGRET_USER => $this->getTargetUserForStartParameters(),
			\CBPDocument::PARAM_MODIFIED_DOCUMENT_FIELDS => (
				$this->document?->hasChangedFields() ? $this->document->getChangedFieldNames() : false
			),
			\CBPDocument::PARAM_DOCUMENT_TYPE => $this->getDocumentTypeForStartParameters(),
		];

		return $this->runMultiWorkflows($startParameters);
	}

	protected function runEventScenario(): bool
	{
		$result = true;

		if (!$this->checkConstraints())
		{
			return false;
		}

		foreach ($this->events as $event)
		{
			$startParameters = array_merge(
				[
					\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => $event->getEventType(),
					\CBPDocument::PARAM_TAGRET_USER => $event->getUserId() > 0 ? 'user_'. $event->getUserId() : null,
					\CBPDocument::PARAM_MODIFIED_DOCUMENT_FIELDS => false,
					\CBPDocument::PARAM_DOCUMENT_TYPE => $event->getDocument()?->complexType ?: null,
					\CBPDocument::PARAM_TRIGGER_EVENT_DATA => $event->getParameters() ?? [],
				],
				// the manual start by a trigger button of the scheme runs this very event scenario, so
				// the mark and not the event type is what tells it from an automatic trigger
				$this->getManualStartSurfaceForStartParameters($event->getUserId()),
			);

			if (!$this->runEvent($event, $startParameters))
			{
				$result = false;
			}
		}

		return $result;
	}

	/**
	 * The mark of a manual start, taken from the surface the caller named: the starter itself does not
	 * know where the start came from, and the entry point does. An automatic scenario never names a
	 * surface, so it never carries the mark and keeps running the common version of the template.
	 *
	 * A start with no employee behind it carries no mark either: the mark exists to choose the version
	 * for a concrete employee, and there is nobody to choose it for.
	 *
	 * @return array<string, string> the parameter to merge into the start parameters, empty when there
	 *     is nothing to mark
	 */
	private function getManualStartSurfaceForStartParameters(int $initiatorId): array
	{
		$surface = $this->context?->manualStartSurface;

		if ($surface === null || $initiatorId <= 0)
		{
			return [];
		}

		return [\CBPDocument::PARAM_MANUAL_START_SURFACE => $surface->value];
	}

	private function checkConstraints(): bool
	{
		foreach ($this->config->constraints as $constraint)
		{
			if (!$constraint->isSatisfied())
			{
				$error = $constraint->getError();
				if ($error)
				{
					$this->errorCollection->add([$error]);
				}

				return false;
			}
		}

		return true;
	}

	private function runEvent(Event $event, array $startParameters): bool
	{
		$document = $event->getDocument();
		if ($document && !$document->getType())
		{
			return true;  // nothing to run
		}

		$code = $event->getCode();
		if (!$code)
		{
			return true; // nothing to run
		}

		[$moduleId, $entity, $documentType] = $document?->complexType ?? Workflow::getComplexType();

		$query =
			WorkflowTemplateTriggerTable::query()
				->setSelect(['TEMPLATE_ID', 'APPLY_RULES', 'TRIGGER_NAME', 'PARAMETERS' => 'TEMPLATE.PARAMETERS'])
				->where('TRIGGER_TYPE', $code)
				->where('MODULE_ID', $moduleId)
				->where('ENTITY', $entity)
				->where('DOCUMENT_TYPE', $documentType)
		;

		$source = $this->getCreateSourceFilter();
		if ($source === CreateSource::User)
		{
			$query->where(
				\Bitrix\Main\ORM\Query\Query::filter()
					->logic('or')
					->where('TEMPLATE.CREATE_SOURCE', CreateSource::User->value)
					->whereNull('TEMPLATE.CREATE_SOURCE')
			);
		}
		else
		{
			$query->where('TEMPLATE.CREATE_SOURCE', $source->value);
		}

		if ($this->templateIds)
		{
			if (count($this->templateIds) === 1)
			{
				$query->where('TEMPLATE_ID', current($this->templateIds));
			}
			else
			{
				$query->whereIn('TEMPLATE_ID', $this->templateIds);
			}
		}

		$triggers = $query->exec();
		if (is_subclass_of($event->getTriggerName(), BaseTrigger::class))
		{
			$code = str_replace('CBP', '', CBPCrmAutomationTrigger::class);
		}

		$restartContext = $this->extractRestartContext($event, $code);
		$restartRunIdAvailable = $restartContext !== null;

		$result = true;
		while ($trigger = $triggers->fetch())
		{
			// On the restart branch the first applicable trigger row starts under the pre-generated
			// restartRunId from the payload, so the run, its per-robot log and the restart-event
			// record all share WORKFLOW_ID = restartRunId (AC-016). A restart template has a single
			// restart trigger (ADR); a hypothetical extra row would collide on the instance id, so
			// only the first row reuses restartRunId and the rest fall back to a fresh id.
			$usesRestartRunId = $restartRunIdAvailable;
			$workflowId = $usesRestartRunId
				? $restartContext['restartRunId']
				: \CBPRuntime::generateWorkflowId()
			;
			$complexId = $document->complexId ?? Workflow::getComplexId($workflowId);

			$templateId = (int)$trigger['TEMPLATE_ID'];
			$applyResult = $this->checkApplyRules(
				$code,
				$trigger['APPLY_RULES'] ?? [],
				$event->getParameters(),
				$templateId,
				$complexId
			);

			if (!$applyResult->isSuccess())
			{
				$this->errorCollection->add($applyResult->getErrors());

				continue;
			}

			$startParameters[\CBPDocument::PARAM_TRIGGER_EVENT] = $trigger['TRIGGER_NAME'];
			$startParameters[\CBPDocument::PARAM_PRE_GENERATED_WORKFLOW_ID] = $workflowId;

			if (\CBPHelper::isEqualDocumentEntity($complexId, Workflow::getComplexType()))
			{
				$startParameters[\CBPDocument::PARAM_IGNORE_SIMULTANEOUS_PROCESSES_LIMIT] = true;
			}

			$parameters = $this->validateParameters($templateId, $trigger['PARAMETERS'], $document?->complexType);
			if ($parameters === null)
			{
				continue;
			}

			if ($usesRestartRunId)
			{
				// Consume only once we actually start, so a row rejected by apply-rules/parameters
				// leaves restartRunId for the next applicable row.
				$restartRunIdAvailable = false;
			}

			// no check constants
			$workflowId = $this->startWorkflow($templateId, $complexId, array_merge($parameters, $startParameters));

			// no meta data
			if (!$workflowId)
			{
				$result = false;
			}
			else
			{
				$this->isTriggerApplied = true;
			}

			if ($usesRestartRunId)
			{
				$this->writeRestartEventTracking($restartContext, (bool)$workflowId);
			}
		}

		if ($restartRunIdAvailable && $restartContext !== null)
		{
			// A restart was requested but no trigger row ever started under restartRunId (every
			// applicable row was rejected by apply-rules/validateParameters, or there was none).
			// The restart run id was never consumed, so writeRestartEventTracking has not run yet;
			// record the failed attempt as faulted so the audit chain keeps an entry (AC-022/ERR-004).
			// This only journals — the start branch is never replayed once a restart has begun.
			$this->writeRestartEventTracking($restartContext, false);
		}

		return $result;
	}

	/**
	 * Reads the restart context out of the process-trigger event, but only for the dedicated restart
	 * trigger ($triggerCode === RESTART_TRIGGER_CODE) carrying a full EVENT-01 restart payload
	 * (runType = restart with a non-empty restartRunId). Returns null for every other event — both
	 * non-restart trigger codes and the restart code without a valid payload — so the start path of
	 * non-restart triggers is left untouched and no other event can borrow restart semantics.
	 *
	 * @param string $triggerCode Resolved TRIGGER_TYPE of the event (Event::getCode()).
	 *
	 * @return array{restartRunId: string, previousRunId: string, initiatorId: int, runType: string}|null
	 */
	private function extractRestartContext(Event $event, string $triggerCode): ?array
	{
		if ($triggerCode !== self::RESTART_TRIGGER_CODE)
		{
			return null;
		}

		$parameters = $event->getParameters();

		$runType = (string)($parameters[self::RESTART_PARAM_RUN_TYPE] ?? '');
		$restartRunId = (string)($parameters[self::RESTART_PARAM_RUN_ID] ?? '');
		if ($runType !== self::RUN_TYPE_RESTART || $restartRunId === '')
		{
			return null;
		}

		return [
			'restartRunId' => $restartRunId,
			'previousRunId' => (string)($parameters[self::RESTART_PARAM_PREVIOUS_RUN_ID] ?? ''),
			'initiatorId' => (int)($parameters[self::RESTART_PARAM_INITIATOR_ID] ?? 0),
			'runType' => $runType,
		];
	}

	/**
	 * Journals the restart event itself into b_bp_tracking, addressed by the restart run id in the
	 * standard WORKFLOW_ID column and attributed to the initiator via MODIFIED_BY. The previous run
	 * id and the run type go into ACTION_NOTE (no dedicated column, see ADR). A start that failed is
	 * recorded with a faulted result and never replayed as a start.
	 *
	 * @param array{restartRunId: string, previousRunId: string, initiatorId: int, runType: string} $restartContext
	 */
	private function writeRestartEventTracking(array $restartContext, bool $started): void
	{
		$trackingService = \CBPRuntime::getRuntime()->getTrackingService();

		// The restart run id is not the started workflow instance, so it never carries the start-time
		// forced-tracking flag; force it here so this audit record is kept even on editions/templates
		// where tracking is otherwise off (RestrictedTracking).
		$trackingService->setForcedMode($restartContext['restartRunId']);

		$trackingService->write(
			$restartContext['restartRunId'],
			\CBPTrackingType::Trigger,
			self::RESTART_TRACKING_ACTION_NAME,
			\CBPActivityExecutionStatus::Closed,
			$started ? \CBPActivityExecutionResult::Succeeded : \CBPActivityExecutionResult::Faulted,
			'',
			sprintf(
				'runType=%s; previousRunId=%s',
				$restartContext['runType'],
				$restartContext['previousRunId'],
			),
			$restartContext['initiatorId'],
		);
	}

	private function buildCreateSourceFilter(): array
	{
		$source = $this->getCreateSourceFilter();
		if ($source === CreateSource::User)
		{
			return [
				'LOGIC' => 'OR',
				['=CREATE_SOURCE' => CreateSource::User->value],
				['=CREATE_SOURCE' => null],
			];
		}

		return ['=CREATE_SOURCE' => $source->value];
	}

	protected function runOnScriptScenario(): bool
	{
		// Script scenario is not supported, only as automation

		return true;
	}

	protected function getTemplatesByScenario(): array
	{
		// script scenario is not supported
		if (in_array($this->config->scenario, [Scenario::onEvent, Scenario::onScript], true))
		{
			return [];
		}

		if ($this->config->scenario === Scenario::onRest && !$this->templateIds)
		{
			return [];
		}

		$document = $this->document;
		if ($document && !$document->complexType)
		{
			return []; // no document, no templates for now
		}

		$complexDocumentType = $this->document?->complexType ?? Workflow::getComplexType();

		$filter = ['DOCUMENT_TYPE' => $complexDocumentType];
		switch ($this->config->scenario)
		{
			case Scenario::onRest:
				$filter['!=AUTO_EXECUTE'] = \CBPDocumentEventType::Automation;
				break;
			case Scenario::onManual:
				$filter['ACTIVE'] = 'Y';
				$filter['<AUTO_EXECUTE'] = \CBPDocumentEventType::Automation;
				break;
			case Scenario::onDocumentAdd:
			case Scenario::onDocumentInnerAdd:
				$filter['ACTIVE'] = 'Y';
				$filter['AUTO_EXECUTE'] = \CBPDocumentEventType::Create;
				break;
			case Scenario::onDocumentUpdate:
			case Scenario::onDocumentInnerUpdate:
				$filter['ACTIVE'] = 'Y';
				$filter['AUTO_EXECUTE'] = \CBPDocumentEventType::Edit;
				break;
			default:
				// nothing to add to filter
		}

		$filter[] = $this->buildCreateSourceFilter();

		if ($this->templateIds)
		{
			if (count($this->templateIds) === 1)
			{
				$filter['=ID'] = current($this->templateIds);
			}
			else
			{
				$filter['@ID'] = $this->templateIds;
			}
		}

		$select = ['ID', 'PARAMETERS'];
		$list = \CBPWorkflowTemplateLoader::getList([], $filter, false, false, $select);

		$templates = [];
		while ($template = $list->fetch())
		{
			$templates[] = $template;
		}

		return $templates;
	}
}
