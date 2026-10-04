<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Agent\Copilot\CallScoringV2BackfillAgent;
use Bitrix\Crm\Agent\Copilot\CallScoringV2BootstrapAgent;
use Bitrix\Crm\Copilot\CallAssessment\Backfill\BackfillService;
use Bitrix\Crm\Copilot\CallScriptMaintenance\CandidatesRepository;
use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Experimental;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Autostart\CallAssessmentDefault;
use Bitrix\Crm\Integration\BizProc\CallAssessmentAiAgent;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class CallScoringV2 extends BaseFeature
{
	private readonly \Closure $backfillNeedProvider;
	private readonly \Closure $bootstrapForcer;
	private readonly \Closure $agentRestorer;

	/**
	 * @param null|\Closure(): bool $backfillNeedProvider
	 * @param null|\Closure(): void $bootstrapForcer
	 * @param null|\Closure(int): Result $agentRestorer
	 */
	public function __construct(
		?\Closure $backfillNeedProvider = null,
		?\Closure $bootstrapForcer = null,
		?\Closure $agentRestorer = null,
	)
	{
		$this->backfillNeedProvider = $backfillNeedProvider ?? self::isBackfillNeeded(...);
		$this->bootstrapForcer = $bootstrapForcer ?? CallScoringV2BootstrapAgent::runNow(...);
		$this->agentRestorer = $agentRestorer ?? static fn(int $userId): Result
			=> (new CallAssessmentAiAgent())->ensureLaunched($userId);
	}

	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_CALL_SCORING_V2_NAME');
	}

	public function getCategory(): BaseCategory
	{
		return Experimental::getInstance();
	}

	/**
	 * The pending state of the data preparation is written before the feature is published, not after it: for
	 * AIManager::isCallScoringV2Enabled() the feature is on as soon as the option of the feature is written, so in
	 * between the two writes the bootstrap agent would create and start the copy over unprepared data.
	 */
	public function enable(): void
	{
		if (($this->backfillNeedProvider)())
		{
			Option::set('crm', AIManager::CALL_SCORING_V2_PENDING_BACKFILL_OPTION_NAME, 'Y');
			parent::enable();
			(new CallAssessmentDefault())->computeAndStore();
			CallScoringV2BackfillAgent::register();

			return;
		}

		$this->activate(function (): void {
			Option::delete('crm', ['name' => AIManager::CALL_SCORING_V2_PENDING_BACKFILL_OPTION_NAME]);
			parent::enable();
			(new CallAssessmentDefault())->computeAndStore();
		});
	}

	/**
	 * The second path the feature turns on by, called by the agent of the data preparation once it is over. The
	 * option of the feature has been written by enable() already, and for AIManager::isCallScoringV2Enabled() the
	 * feature becomes enabled the moment the guard of the preparation is removed - so removing the guard is what
	 * publishes the enabled state here, and the rest of the activation is the same as on the other path.
	 */
	public function activateAfterDataPreparation(): void
	{
		$this->activate(static function (): void {
			Option::delete('crm', ['name' => AIManager::CALL_SCORING_V2_PENDING_BACKFILL_OPTION_NAME]);
		});
	}

	public function disable(): void
	{
		parent::disable();

		Option::delete('crm', ['name' => AIManager::CALL_SCORING_V2_PENDING_BACKFILL_OPTION_NAME]);
		BackfillService::resetInflight();
		(new CallAssessmentDefault())->clear();
	}

	protected function getOptionName(): string
	{
		return 'call_scoring_v2';
	}

	/**
	 * The single order in which the feature turns on, shared by both paths that publish the enabled state.
	 *
	 * The counter of an unfinished fast ladder is dropped first, before anything can read the feature as enabled:
	 * disable() keeps that counter deliberately, so a period of the feature that ended in the middle of the ladder
	 * leaves its value behind. A cycle of the bootstrap agent starting in the window between the publication and the
	 * reset would meet the counter of the previous period next to an enabled feature and could switch a freshly
	 * enabled feature off on its first real refusal - and the restore below would then find it disabled and create
	 * nothing.
	 *
	 * @param \Closure(): void $publishEnabledState
	 */
	private function activate(\Closure $publishEnabledState): void
	{
		CallScoringV2BootstrapAgent::forgetFailedAttempts();

		$publishEnabledState();

		// the copy is created here, and the owner of the invariant is forced as the fallback for a refused restore
		$this->restoreLaunchedCopy();
		($this->bootstrapForcer)();
	}

	/**
	 * Creates the copy right here instead of leaving it to the schedule of the owner of the invariant: the forced
	 * NEXT_EXEC is not guaranteed to survive. A cycle of the agent running at this very moment has already read the
	 * feature as disabled and set the daily period for itself, and CAgent::ExecuteAgents() rewrites NEXT_EXEC by that
	 * period once the cycle ends - no matter when the forcing was written.
	 *
	 * No second copy can appear: the restore is idempotent and mutually excluded by its named lock. A refusal is not
	 * handled here, because the scenario writes its reason to the journal of the feature on its own and the owner of
	 * the invariant repeats the attempt. Only a throwable is recorded, and none is let out: enabling a feature must
	 * not fail because of an auxiliary path.
	 */
	private function restoreLaunchedCopy(): void
	{
		try
		{
			($this->agentRestorer)(CallAssessmentAiAgent::RESTORE_USER_ID);
		}
		catch (\Throwable $exception)
		{
			Container::getInstance()->getLogger(CallAssessmentAiAgent::LOGGER_CHANNEL)->error(
				'{date}: {class}: restore of the agent copy on enabling the feature failed with an exception: {error}',
				[
					'class' => self::class,
					'error' => $exception->getMessage(),
				],
			);
		}
	}

	private static function isBackfillNeeded(): bool
	{
		return !empty((new CandidatesRepository())->findCallAssessmentsWithoutCriteria());
	}
}
