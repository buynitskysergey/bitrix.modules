<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent;

use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\SystemAiAgentLifecycleService;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstanceState;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentInstanceRepositoryInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Periodic continuation of the removals of managed system AI agent instances that one synchronous call could
 * not finish: a pass interrupted by a failure, an amount of data larger than one bounded portion, or an
 * enabling that never reached its final state.
 *
 * The agent owns the selection of the records, the deadline of its own run and the resilience to a failure of
 * a single record. It owns no removal of its own: every record is handed to
 * {@see SystemAiAgentLifecycleService::continueRemoval()}, which acquires the named lock of the identity
 * without waiting, rereads the row and only then takes the record over. A busy record therefore costs the
 * pass nothing and is left to the operation that holds the lock.
 *
 * One instance of the agent runs under a stable name every {@see self::PERIOD_SECONDS} seconds, so neither
 * the installation nor an update creates a second schedule and no name of an agent is built at runtime.
 *
 * The pass is an internal system context: it depends neither on the existence nor on the activity of the
 * stored USER_ID, and it reads neither $USER nor CurrentUser.
 */
final class ManagedSystemAiAgentCleanupAgent
{
	/**
	 * Interval of the schedule, mirrored by install/migrations/agents.php and by the updater.
	 */
	public const PERIOD_SECONDS = 300;

	/**
	 * Records one run reads at most; the pass deadline may leave a part of them for the next run.
	 */
	public const INSTANCE_LIMIT = 20;

	/**
	 * Deadline of the whole run: no new record is taken after it, and the record already being processed is
	 * additionally bounded by the row and time budget of the removal pass itself.
	 */
	public const PASS_SECONDS = 30.0;

	/**
	 * States a background pass takes over. An instance that is being created carries its watchdog deadline in
	 * NEXT_RETRY_AT, therefore the very same "empty or already due" predicate of ix_bp_ma_instance_cleanup
	 * selects an expired enabling and skips a live one.
	 */
	private const DUE_STATES = [
		ManagedAgentInstanceState::Deleting,
		ManagedAgentInstanceState::CleanupPending,
		ManagedAgentInstanceState::Enabling,
	];

	/**
	 * Stable code an unexpected failure of a pass is stored and journaled under; the text of the exception is
	 * never kept.
	 */
	public const CODE_UNEXPECTED_FAILURE = 'AI_AGENT_BACKGROUND_PASS_FAILED';

	/**
	 * Number of charged retries the first warning about a stuck instance is written at, and the period of the
	 * warnings after it.
	 */
	private const STUCK_FIRST_RETRY = 10;

	private const STUCK_RETRY_PERIOD = 24;

	private const LOG_MESSAGE_FAILURE = 'System AI agent background cleanup pass failed';

	private const LOG_MESSAGE_STUCK = 'System AI agent cleanup does not finish';

	private const NANOSECONDS_IN_SECOND = 1000000000;

	private readonly ?ManagedAgentInstanceRepositoryInterface $instanceRepository;

	/**
	 * @var \Closure(ManagedAgentInstance): mixed the entry point of the removal
	 */
	private readonly \Closure $continueRemoval;

	private ?LoggerInterface $logger;

	private bool $loggerResolved = false;

	/**
	 * The agent is an entry point of the platform and is constructed without arguments there, so every
	 * dependency has a default that resolves itself lazily. The removal is taken as a callable and not as the
	 * service itself: the service is final, and this way a test drives the agent without a live lifecycle.
	 *
	 * @param (\Closure(ManagedAgentInstance): mixed)|null $continueRemoval continuation of one record
	 */
	public function __construct(
		?ManagedAgentInstanceRepositoryInterface $instanceRepository = null,
		?\Closure $continueRemoval = null,
		?LoggerInterface $logger = null,
		private readonly int $instanceLimit = self::INSTANCE_LIMIT,
		private readonly float $passSeconds = self::PASS_SECONDS,
	)
	{
		$this->instanceRepository = $instanceRepository ?? Container::getManagedAgentInstanceRepository();
		$this->continueRemoval = $continueRemoval ?? static function(ManagedAgentInstance $instance): mixed {
			$service = ServiceLocator::getInstance()->get(SystemAiAgentLifecycleService::SERVICE_CODE);

			return $service->continueRemoval($instance);
		};
		$this->logger = $logger;
	}

	/**
	 * Entry point of the schedule; the name of the agent is returned even when the pass could not start at all,
	 * so a failure of one run never removes the schedule.
	 */
	public static function runAgent(): string
	{
		try
		{
			(new self())->run();
		}
		catch (\Throwable)
		{
			// The next run repeats the pass; a diagnostic of a record belongs to the record and is written there.
		}

		return __METHOD__ . '();';
	}

	/**
	 * Runs one bounded pass over the records that are due.
	 *
	 * @return int records the pass handed to the removal
	 */
	public function run(): int
	{
		if ($this->instanceRepository === null)
		{
			return 0;
		}

		$deadline = hrtime(true) + (int)round($this->passSeconds * self::NANOSECONDS_IN_SECOND);

		try
		{
			$instances = $this->instanceRepository->findDueByStates(
				self::DUE_STATES,
				new DateTime(),
				$this->instanceLimit,
			);
		}
		catch (\Throwable)
		{
			return 0;
		}

		$processed = 0;
		foreach ($instances as $instance)
		{
			if (hrtime(true) >= $deadline)
			{
				break;
			}

			$this->processInstance($instance);
			$processed++;
		}

		return $processed;
	}

	/**
	 * Hands one record to the removal, keeping the failure of that record inside this method: the next record
	 * of the pass is processed in any case.
	 */
	private function processInstance(ManagedAgentInstance $instance): void
	{
		$retryCountBefore = $instance->getRetryCount();

		try
		{
			($this->continueRemoval)($instance);
		}
		catch (\Throwable)
		{
			// The charged record is already reread, so the warning needs no read of its own.
			$this->reportStuckInstance($this->chargeUnexpectedFailure($instance), $retryCountBefore);

			return;
		}

		$this->reportStuckInstance($this->readInstance($instance), $retryCountBefore);
	}

	/**
	 * Stores the stable code of an unexpected failure and charges a retry by the delays of the lifecycle
	 * service, so a record whose removal keeps throwing cannot occupy every pass.
	 *
	 * The state of the record is left untouched: NEXT_RETRY_AT is the retry deadline of a removal and the
	 * watchdog deadline of an enabling at the same time, therefore postponing it is the whole backoff either
	 * way, and no transition happens outside the named lock the failed pass has already released.
	 *
	 * @return ManagedAgentInstance|null the charged record, or null when it is gone or could not be charged
	 */
	private function chargeUnexpectedFailure(ManagedAgentInstance $instance): ?ManagedAgentInstance
	{
		$retryCount = $instance->getRetryCount() + 1;
		$charged = null;

		try
		{
			$stored = $this->instanceRepository->getById((int)$instance->getId());
			if ($stored !== null)
			{
				$retryCount = $stored->getRetryCount() + 1;

				$charged = $this->instanceRepository->save($stored->withRetry(
					$retryCount,
					SystemAiAgentLifecycleService::describeRetryDeadline($retryCount),
					self::CODE_UNEXPECTED_FAILURE,
					new DateTime(),
				));
			}
		}
		catch (\Throwable)
		{
			// A retry that could not be stored is charged again by the next run of the agent.
		}

		$this->warn(self::LOG_MESSAGE_FAILURE, $instance, $retryCount, self::CODE_UNEXPECTED_FAILURE);

		return $charged;
	}

	/**
	 * Warns about an instance whose removal keeps failing, at the tenth charged retry and every
	 * {@see self::STUCK_RETRY_PERIOD} retries after it.
	 *
	 * Only a pass that really charged a retry is counted - a failure or a blocked continuation: a busy record and
	 * a bounded portion of work leave the counter alone, and confirmed progress resets it.
	 *
	 * @param ManagedAgentInstance|null $stored the record as it is after the pass, or null when it is gone
	 */
	private function reportStuckInstance(?ManagedAgentInstance $stored, int $retryCountBefore): void
	{
		if ($stored === null)
		{
			return;
		}

		$retryCount = $stored->getRetryCount();
		if ($retryCount <= $retryCountBefore || !self::isReportedRetry($retryCount))
		{
			return;
		}

		$this->warn(self::LOG_MESSAGE_STUCK, $stored, $retryCount);
	}

	/**
	 * The record as the pass left it, or null when it is gone or unreadable.
	 *
	 * A pass that ended without an exception may still have charged a retry inside the lifecycle service - that
	 * is what a failed cleanup of a resource does - and the service answers with the public result of the
	 * operation and not with the counter of the record. Reading the record is therefore the only way the agent
	 * learns of a failure it did not raise itself, which is the very case the warning exists for.
	 */
	private function readInstance(ManagedAgentInstance $instance): ?ManagedAgentInstance
	{
		try
		{
			return $this->instanceRepository->getById((int)$instance->getId());
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private static function isReportedRetry(int $retryCount): bool
	{
		return
			$retryCount >= self::STUCK_FIRST_RETRY
			&& ($retryCount - self::STUCK_FIRST_RETRY) % self::STUCK_RETRY_PERIOD === 0
		;
	}

	/**
	 * Writes the structured record of a record that needs attention.
	 *
	 * The identity is represented by the HMAC token the lifecycle service uses, so the records of one instance
	 * correlate across both journals. The identity hash itself, the original context, the parameters, the
	 * constants and the text of an exception have no field here at all.
	 */
	private function warn(
		string $message,
		ManagedAgentInstance $instance,
		int $retryCount,
		?string $reason = null,
	): void
	{
		try
		{
			$logger = $this->getLogger();
			if ($logger === null)
			{
				return;
			}

			$context = [
				'instanceId' => (int)$instance->getId(),
				'state' => $instance->getState()->value,
				'retryCount' => $retryCount,
			];

			$token = self::buildIdentityToken($instance);
			if ($token !== null)
			{
				$context['identityToken'] = $token;
			}

			if ($reason !== null)
			{
				$context['reason'] = $reason;
			}

			$logger->warning($message, $context);
		}
		catch (\Throwable)
		{
			// A diagnostic that could not be written must not break the pass of the remaining records.
		}
	}

	private static function buildIdentityToken(ManagedAgentInstance $instance): ?string
	{
		try
		{
			return (new Signer())->getSignature(
				$instance->getIdentityHash(),
				SystemAiAgentLifecycleService::LOG_IDENTITY_PURPOSE,
			);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Null while the logger is switched off, which is the same journal the lifecycle service writes to.
	 *
	 * The answer is resolved once and kept for the life of the agent, the negative one as well: a pass reports
	 * several records, and a switched off journal would otherwise build the factory for each of them.
	 */
	private function getLogger(): ?LoggerInterface
	{
		if (!$this->loggerResolved)
		{
			$this->loggerResolved = true;
			$this->logger ??= (new LoggerFactory(alwaysReturnLogger: false))
				->createById(SystemAiAgentLifecycleService::LOGGER_ID)
			;
		}

		return $this->logger;
	}
}
