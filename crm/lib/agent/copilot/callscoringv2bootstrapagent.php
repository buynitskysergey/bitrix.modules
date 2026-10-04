<?php

declare(strict_types=1);

namespace Bitrix\Crm\Agent\Copilot;

use Bitrix\Bizproc\Public\Service\Template\NodesInstallerService;
use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\CallScoringV2;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\BizProc\CallAssessmentAiAgent;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Update\CallScoringV2AssessmentDefault;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Owner of the invariant "the call scoring V2 feature is enabled => an active launched copy of the call assessment
 * agent exists". Leaving the schedule is allowed on a confirmed invariant only: every other outcome keeps the agent
 * alive, because nothing else on the portal retries the restore.
 *
 * A failing restore is given a fast ladder of attempts instead of the daily period of the registration: while the
 * feature is enabled without a copy, calls are scored by neither the new nor the previous path. Exhausted attempts
 * mean the cause is not transient, so the feature is switched off - the portal returns to the previous path and
 * becomes observable for an individual investigation.
 */
final class CallScoringV2BootstrapAgent extends AgentBase
{
	public const AGENT_NAME = 'Bitrix\\Crm\\Agent\\Copilot\\CallScoringV2BootstrapAgent::run();';
	public const MODULE_ID = 'crm';

	private const AGENT_CONTINUE = true;
	private const AGENT_STOP = false;
	private const FALLBACK_PERIOD_SECONDS = 86400;
	private const FAST_RETRY_PERIOD_SECONDS = 600;
	private const FAST_RETRY_LIMIT = 5;
	private const FAILED_ATTEMPTS_OPTION_NAME = 'CALL_SCORING_V2_BOOTSTRAP_FAILED_ATTEMPTS';
	private const LOGGER_CHANNEL = CallAssessmentAiAgent::LOGGER_CHANNEL;
	private const AI_AGENT_SECTION_ID = 'AI_AGENT';

	private readonly \Closure $featureStateProvider;
	private readonly \Closure $summaryAgentRegistrar;
	private readonly \Closure $bizprocAvailabilityProvider;
	private readonly \Closure $sectionSyncProvider;
	private readonly \Closure $restoreProvider;
	private readonly \Closure $featureDisabler;
	private readonly \Closure $assessmentDefaultInitializer;
	private readonly LoggerInterface $logger;

	/**
	 * @param null|\Closure(): bool $featureStateProvider
	 * @param null|\Closure(): void $summaryAgentRegistrar
	 * @param null|\Closure(): bool $bizprocAvailabilityProvider
	 * @param null|\Closure(): void $sectionSyncProvider
	 * @param null|\Closure(int): Result $restoreProvider
	 * @param null|\Closure(string): void $featureDisabler
	 * @param null|\Closure(): bool $assessmentDefaultInitializer
	 */
	public function __construct(
		?\Closure $featureStateProvider = null,
		?\Closure $summaryAgentRegistrar = null,
		?\Closure $bizprocAvailabilityProvider = null,
		?\Closure $sectionSyncProvider = null,
		?\Closure $restoreProvider = null,
		?\Closure $featureDisabler = null,
		?LoggerInterface $logger = null,
		?\Closure $assessmentDefaultInitializer = null,
	)
	{
		$this->featureStateProvider = $featureStateProvider ?? AIManager::isCallScoringV2Enabled(...);
		$this->summaryAgentRegistrar = $summaryAgentRegistrar ?? CallScoringV2SummaryAgent::register(...);
		$this->bizprocAvailabilityProvider = $bizprocAvailabilityProvider
			?? static fn(): bool => Loader::includeModule('bizproc');
		$this->sectionSyncProvider = $sectionSyncProvider ?? static function (): void {
			// force: true skips the daily throttle of bizproc, while the lock of the section is taken and released
			// inside the service. A lock it could not take makes the call a silent no-op: the restore below then
			// either finds the copy or reports the missing blueprint, so the agent keeps going either way.
			(new NodesInstallerService())->trySyncSection(self::AI_AGENT_SECTION_ID, force: true);
		};
		$this->restoreProvider = $restoreProvider ?? static fn(int $userId): Result => (new CallAssessmentAiAgent())->ensureLaunched($userId);
		$this->featureDisabler = $featureDisabler ?? Feature::disable(...);
		$this->assessmentDefaultInitializer = $assessmentDefaultInitializer
			?? static fn(): bool => (new CallScoringV2AssessmentDefault())->execute();
		$this->logger = $logger ?? Container::getInstance()->getLogger(self::LOGGER_CHANNEL);
	}

	public static function doRun(): bool
	{
		return (new self())->keepInvariant();
	}

	/**
	 * Synchronizes the AI_AGENT section and then always runs the restore, no matter whether the synchronization
	 * succeeded and whether it happened to invoke a lifecycle hook: the install hook fires once per portal and the
	 * update hook does not fire for an unchanged blueprint, so neither of them can be relied on to create the missing
	 * copy, and only the restore can tell a failed cycle from a copy that is already there.
	 */
	public function keepInvariant(): bool
	{
		if (!($this->featureStateProvider)())
		{
			// not a failed attempt: the counter of the ladder is left as it is
			$this->setExecutionPeriod(self::FALLBACK_PERIOD_SECONDS);

			return self::AGENT_CONTINUE;
		}

		($this->assessmentDefaultInitializer)();
		($this->summaryAgentRegistrar)();

		if (!($this->bizprocAvailabilityProvider)())
		{
			return $this->continueAfterFailure(LogLevel::WARNING, 'bizproc is unavailable');
		}

		if ($this->isSectionSyncDue())
		{
			try
			{
				($this->sectionSyncProvider)();
			}
			catch (\Throwable $exception)
			{
				// bizproc swallows the throwables of invokeLifecycleHook(), the synchronization as a whole does not.
				// The restore below still runs: only it can confirm the invariant, and a copy created meanwhile or on
				// another node of the section would otherwise be given up on together with the feature.
				$this->log(
					LogLevel::ERROR,
					'sync of the {section} section failed: {error}',
					[
						'section' => self::AI_AGENT_SECTION_ID,
						'error' => $exception->getMessage(),
					],
				);
			}
		}

		try
		{
			$restoreResult = ($this->restoreProvider)(CallAssessmentAiAgent::RESTORE_USER_ID);
		}
		catch (\Throwable $exception)
		{
			// the kernel would keep the agent alive on its own, but without shifting NEXT_EXEC and without a reason
			return $this->continueAfterFailure(
				LogLevel::ERROR,
				'restore of the agent copy failed with an exception: {error}',
				['error' => $exception->getMessage()],
			);
		}

		if (!$restoreResult->isSuccess())
		{
			$context = ['errors' => implode('; ', $restoreResult->getErrorMessages())];

			if ($this->isRestoreHeldByAnotherProcess($restoreResult))
			{
				return $this->continueAfterConcurrentRestore($context);
			}

			return $this->continueAfterFailure(
				LogLevel::ERROR,
				'restore of the agent copy failed: {errors}',
				$context,
			);
		}

		self::forgetFailedAttempts();

		return self::AGENT_STOP;
	}

	public static function register(): void
	{
		if (self::findAgentId() > 0)
		{
			return;
		}

		\CAgent::AddAgent(
			self::AGENT_NAME,
			self::MODULE_ID,
			'N',
			self::FALLBACK_PERIOD_SECONDS,
			'',
			'Y',
			\ConvertTimeStamp(time() + CTimeZone::GetOffset() + 60, 'FULL'),
		);
	}

	/**
	 * The forced time is best-effort and may not be relied on alone. The agent is registered with IS_PERIOD = 'N', so
	 * CAgent::ExecuteAgents() ends every cycle with an unconditional NEXT_EXEC = now + the period the cycle asked for,
	 * losing whatever was written meanwhile - and a cycle running right now has read the feature as still disabled and
	 * asked for the daily period. That is why the activation of the feature restores the copy itself as well.
	 *
	 * The ladder of the attempts is not touched here: it is reset by the activation of the feature, which is the only
	 * caller that opens a new period of the feature, and it has to be reset before the enabled state is published.
	 */
	public static function runNow(): void
	{
		$now = (new DateTime())->toString();

		$agentId = self::findAgentId();
		if ($agentId > 0)
		{
			\CAgent::Update($agentId, ['NEXT_EXEC' => $now]);

			return;
		}

		\CAgent::AddAgent(
			self::AGENT_NAME,
			self::MODULE_ID,
			'N',
			self::FALLBACK_PERIOD_SECONDS,
			'',
			'Y',
			$now,
		);
	}

	private static function findAgentId(): int
	{
		$row = \CAgent::GetList(
			[],
			[
				'NAME' => self::AGENT_NAME,
				'MODULE_ID' => self::MODULE_ID,
			],
		)->Fetch();

		return (int)($row['ID'] ?? 0);
	}

	/**
	 * The synchronization is the only cure for a missing system blueprint, and it is the expensive part of the cycle,
	 * so it is performed on the first attempt and repeated on the last fast one as the final chance to close that
	 * cause before the feature is switched off. The attempts in between only restore the copy.
	 */
	private function isSectionSyncDue(): bool
	{
		$failedAttempts = $this->getFailedAttempts();

		return $failedAttempts === 0 || $failedAttempts === self::FAST_RETRY_LIMIT;
	}

	private function isRestoreHeldByAnotherProcess(Result $restoreResult): bool
	{
		return $restoreResult
			->getErrorCollection()
			->getErrorByCode(ErrorCode::CALL_ASSESSMENT_AGENT_RESTORE_IN_PROGRESS) !== null
		;
	}

	/**
	 * A restore that another process is performing right now is not a failed attempt of this one: the invariant is
	 * being closed elsewhere, and spending an attempt on it would switch the feature off while its copy is on the
	 * way. The cycle is carried over instead - by the fast period, because the state it waits for is transient (the
	 * lock lives no longer than the session that holds it), and the window "the feature is on, there is no copy" is
	 * the very thing the ladder hurries to close.
	 *
	 * @param array<string, mixed> $context
	 */
	private function continueAfterConcurrentRestore(array $context): bool
	{
		$this->log(LogLevel::INFO, 'restore of the agent copy is already in progress: {errors}', $context);
		$this->setExecutionPeriod(self::FAST_RETRY_PERIOD_SECONDS);

		return self::AGENT_CONTINUE;
	}

	/**
	 * Records the failure and schedules the next attempt itself: the period of the registration is a day, and the
	 * feature would stay enabled without a copy for that long. Once the fast attempts are spent, the cause is not
	 * transient, and the feature is switched off instead of keeping the gap open.
	 *
	 * @param array<string, mixed> $context
	 */
	private function continueAfterFailure(string $level, string $reason, array $context = []): bool
	{
		$this->log($level, $reason, $context);

		$storedAttempts = $this->getFailedAttempts();
		$failedAttempts = $storedAttempts + 1;
		if ($failedAttempts > self::FAST_RETRY_LIMIT)
		{
			return $this->closeTheLadder($storedAttempts, $reason, $context);
		}

		Option::set(self::MODULE_ID, self::FAILED_ATTEMPTS_OPTION_NAME, (string)$failedAttempts);
		$this->setExecutionPeriod(self::FAST_RETRY_PERIOD_SECONDS);

		return self::AGENT_CONTINUE;
	}

	/**
	 * The decision to give up on the feature is made from the state this cycle read when it started, and it is acted
	 * upon at the end of it. In between an administrator can switch the feature off and on again: the activation of the
	 * new period resets the ladder and restores the copy itself, and switching the feature off afterwards would switch
	 * off a working feature. The window is not the microseconds of one branch either - Option::get answers from the
	 * snapshot of the options the process loads once per hit.
	 *
	 * The counter of the ladder is what tells the periods of the feature apart: the activation drops it before it
	 * publishes the enabled state, and nothing else writes it. So the counter is consumed by a conditional delete
	 * first, and the feature is read again after it - by then the snapshot of the options is dropped, and the state
	 * comes from the database.
	 *
	 * @param int $storedAttempts the value of the counter the decision of this cycle was made on
	 * @param array<string, mixed> $context
	 */
	private function closeTheLadder(int $storedAttempts, string $reason, array $context): bool
	{
		if (!$this->consumeFailedAttempts($storedAttempts))
		{
			$this->log(
				LogLevel::INFO,
				'the ladder belongs to another period of the feature now, the switching off is cancelled',
				$context,
			);
			// the period of the new activation may have refused its own restore, and its ladder starts from here
			$this->setExecutionPeriod(self::FAST_RETRY_PERIOD_SECONDS);

			return self::AGENT_CONTINUE;
		}

		if (!($this->featureStateProvider)())
		{
			$this->log(
				LogLevel::INFO,
				'the feature has been switched off meanwhile, the switching off is cancelled',
				$context,
			);
			$this->setExecutionPeriod(self::FALLBACK_PERIOD_SECONDS);

			return self::AGENT_CONTINUE;
		}

		$this->disableFeature($storedAttempts + 1, $reason, $context);

		return self::AGENT_CONTINUE;
	}

	/**
	 * Drops the counter of the ladder if it still holds the value the decision of this cycle was made on, and reports
	 * whether it did. A counter that holds anything else belongs to another period of the feature, and the decision
	 * carried into this branch is about a period that is over.
	 *
	 * The check is a conditional delete rather than a re-read on purpose: Option::get() is served from the snapshot of
	 * the options of the module the process loaded when the hit started, so a re-read here returns the very value the
	 * decision was made on, no matter what the database holds - and Option::getRealValue() reads the same snapshot. A
	 * conditional delete asks the database itself, and it decides and consumes in one statement, so nothing can change
	 * in between.
	 *
	 * The write goes around Option because it has no compare-and-swap. Option::delete() is called right after it for
	 * the caches only: the value has to disappear from the snapshot of this process and from the managed cache of the
	 * module, and that is what the following read of the state of the feature relies on.
	 */
	private function consumeFailedAttempts(int $storedAttempts): bool
	{
		$connection = Application::getConnection();

		// Option::set() stores the names lower cased, and PostgreSQL compares them case sensitively
		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE ?# = ?s AND ?# = ?s AND ?# = ?s',
				'b_option',
				'MODULE_ID',
				self::MODULE_ID,
				'NAME',
				mb_strtolower(self::FAILED_ATTEMPTS_OPTION_NAME),
				'VALUE',
				(string)$storedAttempts,
			))->compile(),
		);

		if ($connection->getAffectedRowsCount() < 1)
		{
			return false;
		}

		self::forgetFailedAttempts();

		return true;
	}

	/**
	 * The agent stays in the schedule and lands in the branch of the disabled feature on its next run, which is why
	 * the daily period is set here. The counter is already consumed by the check of the period above, so enabling the
	 * feature again meets a fresh ladder.
	 *
	 * @param array<string, mixed> $context
	 */
	private function disableFeature(int $failedAttempts, string $reason, array $context): void
	{
		$message = 'call scoring V2 is switched off automatically after {attempts} failed attempts,'
			. ' the last refusal: ' . $reason;

		$this->log(LogLevel::ERROR, $message, ['attempts' => $failedAttempts] + $context);

		($this->featureDisabler)(CallScoringV2::class);
		$this->setExecutionPeriod(self::FALLBACK_PERIOD_SECONDS);
	}

	private function getFailedAttempts(): int
	{
		return (int)Option::get(self::MODULE_ID, self::FAILED_ATTEMPTS_OPTION_NAME, '0');
	}

	/**
	 * The ladder belongs to one period of the feature: the attempts of the previous one must not shorten it. The
	 * reset is a step of the activation of the feature and is performed by it before the enabled state is published -
	 * a cycle starting in between would meet the counter of the previous period next to an enabled feature.
	 */
	public static function forgetFailedAttempts(): void
	{
		Option::delete(self::MODULE_ID, ['name' => self::FAILED_ATTEMPTS_OPTION_NAME]);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log(string $level, string $message, array $context = []): void
	{
		$this->logger->log(
			$level,
			'{date}: {class}: call assessment agent bootstrap: ' . $message,
			['class' => self::class] + $context,
		);
	}
}
