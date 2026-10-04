<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateSection;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateSectionTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;

final class CallAssessmentAiAgent
{
	public const SYSTEM_CODE = 'bitrix_crm_call_assessment';

	// The restore runs from background and installation paths, where there is no current user to act on behalf of.
	public const RESTORE_USER_ID = 1;

	// Journal of the feature: the shared Integration.AI channel drops an error by its critical threshold.
	public const LOGGER_CHANNEL = 'CallScoring';

	// Key of the blueprint id in the result of findSystemTemplate().
	public const TEMPLATE_ID = 'TEMPLATE_ID';

	private const ORIGIN_SETTING = 'ORIGIN_SETTING';

	private const ORIGIN_CODE = 'ORIGIN_CODE';

	private const SECTION = 'SECTION';

	private readonly \Closure $featureStateProvider;
	private readonly \Closure $activeCopyProvider;
	private readonly \Closure $systemTemplateProvider;
	private readonly \Closure $launchProvider;
	private readonly AgentRestoreLock $restoreLock;

	/**
	 * @param null|\Closure(): bool $featureStateProvider
	 * @param null|\Closure(): ?int $activeCopyProvider
	 * @param null|\Closure(): Result $systemTemplateProvider
	 * @param null|\Closure(int, int): Result $launchProvider
	 */
	public function __construct(
		?\Closure $featureStateProvider = null,
		?\Closure $activeCopyProvider = null,
		?\Closure $systemTemplateProvider = null,
		?\Closure $launchProvider = null,
		?AgentRestoreLock $restoreLock = null,
	)
	{
		$this->featureStateProvider = $featureStateProvider ?? AIManager::isCallScoringV2Enabled(...);
		$this->activeCopyProvider = $activeCopyProvider ?? $this->findActiveLaunchedCopyId(...);
		$this->systemTemplateProvider = $systemTemplateProvider ?? $this->findSystemTemplate(...);
		$this->launchProvider = $launchProvider ?? static fn(int $systemTemplateId, int $userId): Result
			=> (new AiAgentLauncher())->launch($systemTemplateId, $userId);
		$this->restoreLock = $restoreLock ?? new CallAssessmentRestoreLock();
	}

	/**
	 * Restores the active launched copy of the agent and is the only place allowed to create one: the launch it
	 * calls is not idempotent, so a second call would produce a second active copy.
	 *
	 * The feature state is checked here rather than by the caller: that keeps the copy from appearing before the
	 * data preparation is over, no matter which entry point the call came from. No transaction is opened - the
	 * called bizproc scenario is atomic on its own, and mutual exclusion comes from the named lock.
	 *
	 * The predicate is read under the lock only, even at the cost of taking the lock when the invariant already
	 * holds. Read before the lock it may be served by a replica that still shows a copy already removed by the
	 * rollback of a failed parallel launch, and the caller would then drop the bootstrap from the schedule with
	 * no copy in place.
	 */
	public function ensureLaunched(int $userId): Result
	{
		$result = new Result();

		if (!($this->featureStateProvider)())
		{
			return $result;
		}

		if (!$this->restoreLock->acquire())
		{
			return $result->addError(ErrorCode::getAgentRestoreInProgressError());
		}

		try
		{
			if (($this->activeCopyProvider)() !== null)
			{
				return $result;
			}

			$systemTemplateResult = ($this->systemTemplateProvider)();
			if (!$systemTemplateResult->isSuccess())
			{
				$this->logRestoreFailure('no system template to copy: {errors}', [
					'errors' => implode('; ', $systemTemplateResult->getErrorMessages()),
				]);

				return $result->addErrors($systemTemplateResult->getErrors());
			}

			$systemTemplateId = (int)$systemTemplateResult->getData()[self::TEMPLATE_ID];

			$launchResult = ($this->launchProvider)($systemTemplateId, $userId);
			if (!$launchResult->isSuccess())
			{
				$this->logRestoreFailure('launch of template {templateId} failed: {errors}', [
					'templateId' => $systemTemplateId,
					'errors' => implode('; ', $launchResult->getErrorMessages()),
				]);
				$result->addError(ErrorCode::getAgentLaunchFailedError());

				return $result->addErrors($launchResult->getErrors());
			}

			$copyId = ($this->activeCopyProvider)();
			if ($copyId === null)
			{
				$this->logRestoreFailure('launch of template {templateId} succeeded, but no active copy exists', [
					'templateId' => $systemTemplateId,
				]);

				return $result->addError(ErrorCode::getAgentInvariantNotConfirmedError());
			}

			$this->detectSourceChangedWhileCopying($systemTemplateId, $copyId);

			return $result;
		}
		finally
		{
			$this->restoreLock->release();
		}
	}

	/**
	 * Whether the agent has ever been linked to the system code, answered from a cache of a day. Not a check of the
	 * invariant: it tells nothing about a copy being switched on and started, so a switched off and a never launched
	 * copy both count here. Use findActiveLaunchedCopyId() to decide whether a copy has to be created.
	 */
	public function findLaunchedTemplateId(): ?int
	{
		if (!Loader::includeModule('bizproc'))
		{
			return null;
		}

		$row = WorkflowTemplateSettingsTable::query()
			->setSelect(['TEMPLATE_ID'])
			->where('NAME', WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE)
			->where('VALUE', self::SYSTEM_CODE)
			->setCacheTtl(86400)
			->setLimit(1)
			->fetch()
		;

		$templateId = (int)($row['TEMPLATE_ID'] ?? 0);

		return $templateId > 0 ? $templateId : null;
	}

	/**
	 * The id of the active launched copy of the agent, or null when there is none - the check of the invariant, and
	 * the identity of the copy for the journal. Mirrors the canonical predicate of the module that owns the template
	 * model (Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository): a copy (SYSTEM_CODE IS NULL) of the
	 * AI-agent kind (TYPE = Nodes, a row in the AI_AGENT section) linked to this system code that has been started
	 * (ACTIVATED_AT IS NOT NULL) and is switched on (ACTIVE = 'Y'). The whole criterion is reproduced because the
	 * canonical repository lives in the Internal namespace of bizproc: a template that lost its section is not a
	 * working copy of the agent for BizProc, so a narrower predicate here would confirm the invariant while the call
	 * assessment stays down.
	 *
	 * Reads without cache on purpose: this predicate decides whether a copy has to be created, and a stale
	 * negative answer under the restore lock produces the very duplicate the lock protects from. Staleness of a
	 * replica is closed by the lock itself, which keeps the connection on the master while it is held. That is
	 * why findLaunchedTemplateId() cannot be reused here - it caches, and its criterion is the link alone.
	 */
	public function findActiveLaunchedCopyId(): ?int
	{
		if (!Loader::includeModule('bizproc'))
		{
			return null;
		}

		return $this->resolveActiveLaunchedCopyId(self::SYSTEM_CODE);
	}

	/**
	 * The origin of a copy is compared here rather than by the column of the setting: ORIGIN_SYSTEM_CODE is stored in a
	 * text column that MySQL compares case insensitively, while the check of the hidden code in BizProc is an identity
	 * check. A copy launched from a differently cased code is somebody else's template for BizProc, and counting it as
	 * the copy of this agent would confirm the invariant with no working agent behind it - the same strictness the
	 * source of the copy is chosen with in resolveSystemTemplate().
	 *
	 * The whole set of the copies the column accepts is read for the same reason the candidate blueprints are: a
	 * limited select would spend its slot on a row that merely resembles the code and hide the real copy behind it. The
	 * set is tiny by nature, and the rows one copy is multiplied into by the section join answer the same.
	 */
	private function resolveActiveLaunchedCopyId(string $systemCode): ?int
	{
		foreach ($this->buildActiveLaunchedCopyQuery($systemCode)->fetchAll() as $row)
		{
			if ($row[self::ORIGIN_CODE] === $systemCode)
			{
				return (int)$row['ID'];
			}
		}

		return null;
	}

	/**
	 * The blueprint the restore copies, or the reason there is none to be trusted with a launch on behalf of
	 * RESTORE_USER_ID.
	 *
	 * @return Result data key TEMPLATE_ID with the id of the blueprint, or one of two errors:
	 *  CALL_ASSESSMENT_AGENT_SYSTEM_TEMPLATE_MISSING when nothing matches the criterion of a copy source, and
	 *  CALL_ASSESSMENT_AGENT_SYSTEM_TEMPLATE_REJECTED when what matches it may not be copied.
	 */
	public function findSystemTemplate(): Result
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(ErrorCode::getAgentSystemTemplateMissingError());
		}

		return $this->resolveSystemTemplate(self::SYSTEM_CODE);
	}

	/**
	 * Mirrors the criterion of the canonical copy source of BizProc (AiAgentRepository::findCopyableAiAgentTemplate,
	 * the resolver of the grid action): a template of the AI-agent kind (TYPE = Nodes) from the AI_AGENT section
	 * that is not a launched copy itself (ACTIVATED_AT IS NULL). A row outside that criterion is a workflow of
	 * another kind, and a copy of it would run as an agent of the call assessment while assessing nothing.
	 *
	 * Two conditions are added on top of the canonical criterion, and both refuse the row instead of copying it:
	 *
	 * - IS_MODIFIED = 'N'. The synchronization never overwrites a template edited by hand
	 *   (NodesInstallerService::installFromDir), so such a graph is no longer the one of the delivered package,
	 *   while the copy of it would be started on behalf of RESTORE_USER_ID;
	 * - a single trusted template. SYSTEM_CODE carries no unique index, and the comparison of the column is case
	 *   insensitive, so a duplicate or a differently cased code would make the choice of the source arbitrary.
	 *   The identity of the code is therefore verified in PHP, not by the column comparison alone.
	 *
	 * Both conditions are decided over templates, not over rows of the select: the section join multiplies the row
	 * of a template carrying more than one AI_AGENT section row, which the unique key of the section table
	 * (TEMPLATE_ID, SECTION_ID, PATH) allows while PATH is null. Counting rows would refuse a valid blueprint,
	 * and a limited select would let an untrusted row take a slot and hide a trusted duplicate behind it.
	 */
	private function resolveSystemTemplate(string $systemCode): Result
	{
		$result = new Result();

		$candidates = $this->fetchSystemTemplateCandidates($systemCode);
		if (empty($candidates))
		{
			return $result->addError(ErrorCode::getAgentSystemTemplateMissingError());
		}

		$trusted = array_filter(
			$candidates,
			static fn(array $row): bool => $row['SYSTEM_CODE'] === $systemCode && $row['IS_MODIFIED'] === 'N',
		);

		if (count($trusted) !== 1)
		{
			return $result->addError(ErrorCode::getAgentSystemTemplateRejectedError());
		}

		return $result->setData([self::TEMPLATE_ID => (int)reset($trusted)['ID']]);
	}

	/**
	 * The whole set of matching templates, keyed by id, so that the multiplied rows of one template count once. The
	 * set is tiny by nature - unlaunched Nodes templates of the AI_AGENT section carrying this very system code -
	 * so it is read unbounded: no bound can be smaller than the set the criterion has to see in full.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchSystemTemplateCandidates(string $systemCode): array
	{
		$candidates = [];
		foreach ($this->buildSystemTemplateQuery($systemCode)->fetchAll() as $row)
		{
			$candidates[(int)$row['ID']] = $row;
		}

		return $candidates;
	}

	private function buildSystemTemplateQuery(string $systemCode): Query
	{
		$query = WorkflowTemplateTable::query()
			->setSelect(['ID', 'SYSTEM_CODE', 'IS_MODIFIED'])
			->where('SYSTEM_CODE', $systemCode)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('ACTIVATED_AT')
		;

		$query->registerRuntimeField(
			self::SECTION,
			new Reference(
				self::SECTION,
				WorkflowTemplateSectionTable::class,
				Join::on('this.ID', 'ref.TEMPLATE_ID'),
			),
		);

		return $query->where(self::SECTION . '.SECTION_ID', WorkflowTemplateSection::AiAgent->value);
	}

	private function buildActiveLaunchedCopyQuery(string $systemCode): Query
	{
		$query = WorkflowTemplateTable::query()
			->setSelect(['ID', self::ORIGIN_CODE => self::ORIGIN_SETTING . '.VALUE'])
			->whereNull('SYSTEM_CODE')
			->whereNotNull('ACTIVATED_AT')
			->where('ACTIVE', 'Y')
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->addOrder('ID')
		;

		// Unlike the origin link below, the section predicate belongs in the WHERE: a copy that carries no
		// AI_AGENT section row has to be rejected, which is exactly what a null-rejecting join does.
		$query->registerRuntimeField(
			self::SECTION,
			new Reference(
				self::SECTION,
				WorkflowTemplateSectionTable::class,
				Join::on('this.ID', 'ref.TEMPLATE_ID'),
			),
		);
		$query->where(self::SECTION . '.SECTION_ID', WorkflowTemplateSection::AiAgent->value);

		// The NAME predicate stays in the ON clause: in the WHERE it makes the join null-rejecting.
		$query->registerRuntimeField(
			self::ORIGIN_SETTING,
			new Reference(
				self::ORIGIN_SETTING,
				WorkflowTemplateSettingsTable::class,
				Join::on('this.ID', 'ref.TEMPLATE_ID')
					->where('ref.NAME', WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE),
			),
		);

		// the narrowing predicate of the candidates only: their origin is compared in resolveActiveLaunchedCopyId()
		return $query->where(self::ORIGIN_SETTING . '.VALUE', $systemCode);
	}

	/**
	 * Detection, not prevention. The graph of the source is read again inside the launch, so between the check of its
	 * trust and that read anyone allowed to publish the template can edit it: the restore lock coordinates the restores
	 * with each other and knows nothing about the editor of BizProc. Preventing that needs a lock or a transaction
	 * shared with the paths that edit the template, and those belong to the module that owns the templates.
	 *
	 * The outcome stays a fulfilled invariant and the restore stays successful: the copy exists and is active, the
	 * scenario cannot delete it, and reporting a failure would only spend an attempt of the owner of the invariant -
	 * the next cycle of which would find the same active copy and calm down. What the outcome does deserve is a trace,
	 * because it is the only sign that the graph that has been started may not be the delivered one.
	 */
	private function detectSourceChangedWhileCopying(int $systemTemplateId, int $copyId): void
	{
		$recheckResult = ($this->systemTemplateProvider)();
		if (
			$recheckResult->isSuccess()
			&& (int)($recheckResult->getData()[self::TEMPLATE_ID] ?? 0) === $systemTemplateId
		)
		{
			return;
		}

		$this->logRestoreFailure(
			'template {templateId} is not a trusted source anymore, so the copy {copyId} started from it may carry'
				. ' a changed graph: {errors}',
			[
				'templateId' => $systemTemplateId,
				'copyId' => $copyId,
				'errors' => implode('; ', $recheckResult->getErrorMessages()),
			],
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function logRestoreFailure(string $message, array $context = []): void
	{
		Container::getInstance()->getLogger(self::LOGGER_CHANNEL)->error(
			'{date}: {class}: call assessment agent restore: ' . $message,
			['class' => self::class] + $context,
		);
	}
}
