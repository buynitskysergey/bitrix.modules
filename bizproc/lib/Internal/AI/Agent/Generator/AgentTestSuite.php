<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator;

final class AgentTestSuite
{
	/**
	 * @param list<string> $testFiles
	 */
	public function __construct(
		public readonly string $agentName,
		public readonly string $testsDirectory,
		public readonly array $testFiles,
		public readonly string $bootstrapPath,
		public readonly string $phpUnitPath,
	)
	{
	}

	public function hasTests(): bool
	{
		return !empty($this->testFiles);
	}
}
