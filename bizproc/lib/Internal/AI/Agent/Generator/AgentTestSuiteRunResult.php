<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator;

final class AgentTestSuiteRunResult
{
	/**
	 * @param list<string> $failedLabels basenames of the test files that exited non-zero
	 * @param string|null  $skippedReason why the suite was not run (e.g. phpunit unavailable); null when it ran or there were no tests
	 */
	public function __construct(
		public readonly AgentTestSuite $suite,
		public readonly bool $executed,
		public readonly int $exitCode,
		public readonly string $stdout,
		public readonly string $stderr,
		public readonly array $failedLabels = [],
		public readonly ?string $skippedReason = null,
	)
	{
	}

	public function isSuccessful(): bool
	{
		return !$this->executed || $this->exitCode === 0;
	}
}
