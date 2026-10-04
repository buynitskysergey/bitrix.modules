<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\AiAgent\Result;

use Bitrix\Bizproc\Public\Command\AiAgent\Enum\SystemAiAgentErrorCode;
use Bitrix\Bizproc\Public\Command\AiAgent\Enum\SystemAiAgentLifecycleOutcome;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * Result of a command that enables or fully deletes a system AI agent.
 *
 * The result exposes the outcome of the operation only: neither the id of the template, nor the id of the
 * managed instance, nor its identity hash leaves the module. A rejection is a value of the same contract, so
 * an invalid argument comes back as a failed result with a stable code instead of an exception.
 *
 * An unsuccessful outcome comes together with the error whenever the operation did reach a state: a cleanup
 * saved for a retry is a failed result whose outcome is {@see SystemAiAgentLifecycleOutcome::CleanupPending}.
 *
 * Build the result through the factories below: they keep the invariant that {@see self::isSuccess()} agrees
 * with {@see SystemAiAgentLifecycleOutcome::isSuccessful()} of the outcome.
 */
final class SystemAiAgentLifecycleResult extends Result
{
	private function __construct(private readonly ?SystemAiAgentLifecycleOutcome $outcome)
	{
		parent::__construct();
	}

	/**
	 * Builds the result of an operation that succeeded.
	 *
	 * @param SystemAiAgentLifecycleOutcome $outcome state the instance was left in
	 * @return self
	 * @throws ArgumentException when the outcome describes an unsuccessful operation
	 */
	public static function createSuccess(SystemAiAgentLifecycleOutcome $outcome): self
	{
		if (!$outcome->isSuccessful())
		{
			throw new ArgumentException('Outcome of a successful operation is expected.', 'outcome');
		}

		return new self($outcome);
	}

	/**
	 * Builds the result of an operation that was rejected.
	 *
	 * @param SystemAiAgentErrorCode $code stable reason for the rejection
	 * @param SystemAiAgentLifecycleOutcome|null $outcome state the instance was left in, null when the
	 * operation did not get as far as determining it
	 * @return self
	 * @throws ArgumentException when the outcome describes a successful operation
	 */
	public static function createFailure(
		SystemAiAgentErrorCode $code,
		?SystemAiAgentLifecycleOutcome $outcome = null,
	): self
	{
		if ($outcome !== null && $outcome->isSuccessful())
		{
			throw new ArgumentException('Outcome of a successful operation is not expected.', 'outcome');
		}

		$result = new self($outcome);
		$result->addError($code->createError());

		return $result;
	}

	/**
	 * Returns the state the instance was left in, or null when the operation did not get as far as
	 * determining it.
	 *
	 * @return SystemAiAgentLifecycleOutcome|null
	 */
	public function getOutcome(): ?SystemAiAgentLifecycleOutcome
	{
		return $this->outcome;
	}

	/**
	 * Returns the reason for the rejection, or null for a successful result.
	 *
	 * A code this build does not declare reaches the caller as null; the error itself keeps such a code and
	 * is available through {@see self::getErrors()}.
	 *
	 * @return SystemAiAgentErrorCode|null
	 */
	public function getErrorCode(): ?SystemAiAgentErrorCode
	{
		$code = $this->getError()?->getCode();

		return is_string($code) ? SystemAiAgentErrorCode::tryFrom($code) : null;
	}
}
