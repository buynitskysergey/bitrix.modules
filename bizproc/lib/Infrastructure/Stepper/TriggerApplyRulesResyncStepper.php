<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Stepper;

use Bitrix\Bizproc\Starter\Dto\TriggerUpgradeDto;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Update\Stepper;

/**
 * Rebuilds the apply rules of the deprecated trigger rows stored on an existing portal.
 *
 * The rules of such a trigger used to be empty, and an empty rule set is read as "applies to any document":
 * the applicability check never runs, so the filter by entity type and category does not work. Fixing the
 * activity class alone does not help the rows already stored - APPLY_RULES is written only when a template
 * is added or updated. The resync has to call activity code, which the updater cannot do (it runs before
 * the module files are copied), hence a stepper.
 *
 * The table has no autoincrement key - its primary key is composite - so the cursor walks template ids.
 */
class TriggerApplyRulesResyncStepper extends Stepper
{
	protected static $moduleId = 'bizproc';

	private const STEP_TEMPLATES_LIMIT = 50;
	private const UPGRADE_SOURCE_RECHECK_INTERVAL = 3600;
	private const UPGRADE_SOURCE_WAIT_LIMIT = 30 * 86400;

	public function execute(array &$option): bool
	{
		if (!Application::getConnection()->isTableExists(WorkflowTemplateTriggerTable::getTableName()))
		{
			return self::FINISH_EXECUTION;
		}

		if (!isset($option['triggerTypes']))
		{
			return $this->startPass($option);
		}

		$templateIds = $this->selectTemplateIds((array)$option['triggerTypes'], (int)$option['lastId']);
		if (!$templateIds)
		{
			return self::FINISH_EXECUTION;
		}

		foreach ($templateIds as $templateId)
		{
			try
			{
				WorkflowTemplateTriggerTable::resyncApplyRules($templateId);
			}
			catch (\Throwable $exception)
			{
				// the template id has to be in the message: the cursor never comes back to a failed template,
				// while the parameters of the trace - where the id would otherwise be - are not logged by default
				Application::getInstance()->getExceptionHandler()->writeToLog(
					new \RuntimeException(
						'Trigger apply rules resync failed, template id: ' . $templateId,
						0,
						$exception,
					),
				);
			}

			// the cursor advances even for a template that failed to resync: otherwise its rows would come
			// back in every next selection and the pass would never move on
			$option['lastId'] = $templateId;
			$option['steps']++;
		}

		// only an empty selection finishes the pass. count and steps are progress indicators: count is a
		// snapshot taken once at the start of the pass, so a template stored or emptied after it would be
		// left unfixed if the counters decided when to stop
		return self::CONTINUE_EXECUTION;
	}

	/**
	 * The types to resync are asked of the module that owns the documents, so a retired class name of a
	 * foreign module never lives in bizproc. The types are stored in the option: the pass keeps working with
	 * the set it started from, and a step costs no extra query.
	 */
	private function startPass(array &$option): bool
	{
		if (isset($option['recheckTime']) && time() < (int)$option['recheckTime'])
		{
			$this->deferNextRun((int)$option['recheckTime']);

			return self::CONTINUE_EXECUTION;
		}

		$triggerTypes = $this->collectDeprecatedTriggerTypes();
		if (!$triggerTypes)
		{
			return $this->waitForUpgradeSource($option);
		}

		unset($option['recheckTime'], $option['waitStartTime']);

		$option['triggerTypes'] = $triggerTypes;
		$option['count'] = $this->countTemplatesToResync($triggerTypes);
		$option['steps'] = 0;
		$option['lastId'] = 0;

		return $option['count'] > 0 ? self::CONTINUE_EXECUTION : self::FINISH_EXECUTION;
	}

	/**
	 * This stepper is registered by an update of bizproc, while both the upgrade map and the activity that
	 * builds the rules arrive with the update of the module owning the documents. Until that module is
	 * updated the map is empty, and finishing here would delete the agent for good - the rules would stay
	 * broken forever. So the pass waits instead of finishing, rechecking the map on a timer rather than on
	 * every agent run. The wait is bounded: on a portal where the map never appears (no such module at all)
	 * the agent has to go away on its own.
	 */
	private function waitForUpgradeSource(array &$option): bool
	{
		$now = time();
		$option['waitStartTime'] ??= $now;
		$option['recheckTime'] = $now + self::UPGRADE_SOURCE_RECHECK_INTERVAL;

		if ($now - (int)$option['waitStartTime'] >= self::UPGRADE_SOURCE_WAIT_LIMIT)
		{
			return self::FINISH_EXECUTION;
		}

		$this->deferNextRun($option['recheckTime']);

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * The agent is registered with a one second interval, so an idle pass would be repeated every second for
	 * the whole hour it has nothing to do. The scheduler takes the next run time from the period the agent
	 * leaves behind. The base Stepper overwrites that period with its own delay only on a pass longer than
	 * Stepper::THRESHOLD_TIME, and the next pass, an early return, restores the rest of the deferral.
	 *
	 * @see \CAgent::ExecuteAgents
	 * @see Stepper::execAgent
	 */
	private function deferNextRun(int $recheckTime): void
	{
		global $pPERIOD;

		$pPERIOD = max($recheckTime - time(), 1);
	}

	/**
	 * @return list<string>
	 */
	protected function collectDeprecatedTriggerTypes(): array
	{
		$documentService = \CBPRuntime::getRuntime()->getDocumentService();

		$triggerTypes = [];
		foreach ($this->selectDocumentTypes() as $complexDocumentType)
		{
			$moduleSettings = $documentService->getStarterModuleSettings($complexDocumentType);
			if ($moduleSettings === null)
			{
				continue;
			}

			foreach ($moduleSettings->getTriggerUpgradeMap() as $deprecatedType => $upgrade)
			{
				if ($upgrade instanceof TriggerUpgradeDto && (string)$deprecatedType !== '')
				{
					$triggerTypes[(string)$deprecatedType] = true;
				}
			}
		}

		return array_keys($triggerTypes);
	}

	/**
	 * Every document type the stored trigger rows belong to, whatever their rules are: the rules of a row do
	 * not tell which types its module has retired, and the wider selection is the one ix_bp_wtt_med covers.
	 *
	 * @return list<array{string, string, string}>
	 */
	private function selectDocumentTypes(): array
	{
		$sql = (new SqlExpression(
			'SELECT DISTINCT MODULE_ID, ENTITY, DOCUMENT_TYPE FROM ?#',
			WorkflowTemplateTriggerTable::getTableName(),
		))->compile();

		$iterator = Application::getConnection()->query($sql);

		$documentTypes = [];
		while ($row = $iterator->fetch())
		{
			$moduleId = (string)($row['MODULE_ID'] ?? '');
			$entity = (string)($row['ENTITY'] ?? '');
			$documentType = (string)($row['DOCUMENT_TYPE'] ?? '');

			if ($moduleId !== '' && $entity !== '' && $documentType !== '')
			{
				$documentTypes[] = [$moduleId, $entity, $documentType];
			}
		}

		return $documentTypes;
	}

	/**
	 * Templates, not rows: the base Stepper draws the progress bar as steps/count, and a step processes one
	 * template regardless of how many matching rows it owns.
	 *
	 * @param list<string> $triggerTypes
	 */
	private function countTemplatesToResync(array $triggerTypes): int
	{
		[$condition, $parameters] = $this->buildSelectionCondition($triggerTypes);

		$sql = (new SqlExpression(
			'SELECT COUNT(DISTINCT TEMPLATE_ID) FROM ?# WHERE ' . $condition,
			WorkflowTemplateTriggerTable::getTableName(),
			...$parameters,
		))->compile();

		return (int)Application::getConnection()->queryScalar($sql);
	}

	/**
	 * @param list<string> $triggerTypes
	 *
	 * @return list<int>
	 */
	private function selectTemplateIds(array $triggerTypes, int $lastId): array
	{
		if (!$triggerTypes)
		{
			return [];
		}

		[$condition, $parameters] = $this->buildSelectionCondition($triggerTypes);

		// DISTINCT is required: one template can own several matching rows, while the cursor walks templates
		$sql = (new SqlExpression(
			'SELECT DISTINCT TEMPLATE_ID FROM ?# WHERE ' . $condition
			. ' AND TEMPLATE_ID > ?i ORDER BY TEMPLATE_ID ASC',
			WorkflowTemplateTriggerTable::getTableName(),
			...[...$parameters, $lastId],
		))->compile();

		$iterator = Application::getConnection()->query($sql, self::STEP_TEMPLATES_LIMIT);

		$templateIds = [];
		while ($row = $iterator->fetch())
		{
			$templateIds[] = (int)$row['TEMPLATE_ID'];
		}

		return $templateIds;
	}

	/**
	 * What counts as rules never built belongs to the table: the repair writes a row only while it still
	 * matches the very condition the row was selected by.
	 *
	 * @param list<string> $triggerTypes
	 *
	 * @return array{0: string, 1: list<string>}
	 */
	private function buildSelectionCondition(array $triggerTypes): array
	{
		$placeholders = implode(', ', array_fill(0, count($triggerTypes), '?s'));
		[$emptyRulesCondition, $emptyRulesParameters] = WorkflowTemplateTriggerTable::getEmptyApplyRulesCondition();
		$condition = 'TRIGGER_TYPE IN (' . $placeholders . ') AND ' . $emptyRulesCondition;

		return [$condition, [...array_values($triggerTypes), ...$emptyRulesParameters]];
	}
}
