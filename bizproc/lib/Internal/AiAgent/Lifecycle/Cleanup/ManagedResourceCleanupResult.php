<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup;

use Bitrix\Main\ArgumentTypeException;

/**
 * Outcome of one call of a cleanup participant, plus an optional stable code.
 *
 * Four outcomes, and none of them collapses into a success flag: complete, a continuation of a bounded portion
 * this pass really did, a continuation that waits for an external dependency, and a failure. Only the participant
 * is able to tell the two continuations apart, and they are answered differently -
 * {@see self::chargesRetryDelay()} - so the outcome is carried by this value object and is translated into
 * {@see \Bitrix\Main\Result} once, at the boundary of the public command.
 *
 * The code is a stable internal identifier of the reason and never a message: it is stored as the last error
 * code of the instance and is written to the log, so the text of an exception, a value of the caller and any
 * detail of a resource have no way in. The alphabet is checked for that reason alone.
 */
final class ManagedResourceCleanupResult
{
	/**
	 * Length of the stored last error code of the instance.
	 */
	public const MAX_CODE_LENGTH = 64;

	private const CODE_PATTERN = '/^[A-Za-z0-9_.]+$/D';

	private const OUTCOME_COMPLETE = 'complete';

	private const OUTCOME_PENDING = 'pending';

	private const OUTCOME_BLOCKED = 'blocked';

	private const OUTCOME_FAILED = 'failed';

	private function __construct(
		private readonly string $outcome,
		private readonly ?string $code,
	)
	{
	}

	/**
	 * The completion criterion of the resource type is confirmed, so the caller may drop the service row and
	 * move on to the next type.
	 */
	public static function createComplete(): self
	{
		return new self(self::OUTCOME_COMPLETE, null);
	}

	/**
	 * A bounded portion is done or an external deletion is accepted, and actual work remains for the next
	 * pass. The service row of the resource stays, and the counter of retries is left untouched.
	 *
	 * @param string|null $code stable internal reason of the continuation, for the log
	 * @throws ArgumentTypeException when the code is not a stable internal identifier
	 */
	public static function createPending(?string $code = null): self
	{
		return new self(self::OUTCOME_PENDING, self::assertCode($code));
	}

	/**
	 * An external dependency accepted the operation and the resource is still there: the deletion of a bot was
	 * taken, the termination of a workflow was taken, the row of a schedule was dropped, and the resource
	 * answers as live all the same.
	 *
	 * The row stays and the pass is repeated, as with every continuation, but this one is not a portion of work
	 * this pass has done. Nothing about it changes by itself, therefore it is charged with the retry delay of the
	 * failures: without it the same question would be asked every few minutes forever, the counter of retries
	 * would never reach the warning about a record that got stuck and the record would keep the first place of
	 * every selection of the background pass.
	 *
	 * @param string $code stable internal reason of the continuation, for the log
	 * @throws ArgumentTypeException when the code is not a stable internal identifier
	 */
	public static function createBlocked(string $code): self
	{
		return new self(self::OUTCOME_BLOCKED, self::assertCode($code));
	}

	/**
	 * The participant could not reach progress within its own deadline, or the technical data of the resource
	 * did not pass the closed schema of its type. The instance keeps the code and waits for a retry delay.
	 *
	 * @param string|null $code stable internal reason of the failure
	 * @throws ArgumentTypeException when the code is not a stable internal identifier
	 */
	public static function createFailed(?string $code = null): self
	{
		return new self(self::OUTCOME_FAILED, self::assertCode($code));
	}

	public function isComplete(): bool
	{
		return $this->outcome === self::OUTCOME_COMPLETE;
	}

	public function isPending(): bool
	{
		return $this->outcome === self::OUTCOME_PENDING;
	}

	public function isFailed(): bool
	{
		return $this->outcome === self::OUTCOME_FAILED;
	}

	/**
	 * Whether the continuation waits for an external dependency instead of carrying on a portion of work of its
	 * own.
	 */
	public function isBlocked(): bool
	{
		return $this->outcome === self::OUTCOME_BLOCKED;
	}

	/**
	 * Whether the next attempt of this instance has to be delayed: the work was not shrunk by this pass, either
	 * because it failed or because it waits for a dependency that changes nothing on its own.
	 */
	public function chargesRetryDelay(): bool
	{
		return $this->outcome === self::OUTCOME_FAILED || $this->outcome === self::OUTCOME_BLOCKED;
	}

	/**
	 * Stable internal code of the reason, or null when the outcome carries none.
	 */
	public function getCode(): ?string
	{
		return $this->code;
	}

	private static function assertCode(?string $code): ?string
	{
		if ($code === null)
		{
			return null;
		}

		if (strlen($code) > self::MAX_CODE_LENGTH || preg_match(self::CODE_PATTERN, $code) !== 1)
		{
			throw new ArgumentTypeException('code', 'stable internal code of [A-Za-z0-9_.] characters');
		}

		return $code;
	}
}
