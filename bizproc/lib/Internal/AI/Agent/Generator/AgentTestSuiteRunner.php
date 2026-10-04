<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator;

final class AgentTestSuiteRunner
{
	/** How many trailing output lines of a failing test file to keep in the run result. */
	private const OUTPUT_TAIL_LINES = 20;

	/** How many failing test files to include output for (the rest are listed by name only). */
	private const MAX_DETAILED_FAILURES = 3;

	/** @var null|\Closure(array, array, string): array{exitCode:int, stdout:string, stderr:string} */
	private readonly ?\Closure $executor;

	public function __construct(
		private readonly AgentTestSuiteResolver $resolver,
		?\Closure $executor = null,
	)
	{
		$this->executor = $executor;
	}

	public function run(string $agentName, string $documentRoot): AgentTestSuiteRunResult
	{
		return $this->runSuite($this->resolver->resolve($agentName), $documentRoot);
	}

	public function runSuite(AgentTestSuite $suite, string $documentRoot): AgentTestSuiteRunResult
	{
		if (!$suite->hasTests())
		{
			return new AgentTestSuiteRunResult($suite, false, 0, '', '');
		}

		// A missing test runtime (no phpunit / bootstrap) is not a test failure: skip the suite with a
		// reason instead of throwing, so the caller can keep the generated files and just warn.
		$skippedReason = $this->missingPrerequisite($suite);
		if ($skippedReason !== null)
		{
			return new AgentTestSuiteRunResult($suite, false, 0, '', '', [], $skippedReason);
		}

		$env = ['DOCUMENT_ROOT' => rtrim($documentRoot, '/')];
		$workingDirectory = dirname($suite->phpUnitPath, 3);

		$aggregateExitCode = 0;
		$failedLabels = [];
		$stdoutChunks = [];
		$stderrChunks = [];

		// Each test class is executed in its own phpunit process on purpose: the
		// agent harness declares stub activity classes at file scope, so loading
		// several test files into a single process fatals on duplicate class
		// declaration and produces false failures.
		//
		// Only failing files contribute to the captured output, and each is
		// trimmed to its tail — the phpunit failure/error block and summary live
		// at the end, while the banner is noise. This keeps the command's error
		// message readable even when every class fails on the same setup error.
		foreach ($suite->testFiles as $testFile)
		{
			$result = $this->execute($this->buildCommand($suite, $testFile), $env, $workingDirectory);
			if ($result['exitCode'] === 0)
			{
				continue;
			}

			$label = basename($testFile);
			$failedLabels[] = $label;
			$aggregateExitCode = $result['exitCode'];

			// Keep output only for the first few failures; the rest are named in failedLabels.
			if (count($failedLabels) > self::MAX_DETAILED_FAILURES)
			{
				continue;
			}

			$stdout = self::tail(trim($result['stdout']), self::OUTPUT_TAIL_LINES);
			if ($stdout !== '')
			{
				$stdoutChunks[] = "### {$label}\n{$stdout}";
			}

			$stderr = self::tail(trim($result['stderr']), self::OUTPUT_TAIL_LINES);
			if ($stderr !== '')
			{
				$stderrChunks[] = "### {$label}\n{$stderr}";
			}
		}

		return new AgentTestSuiteRunResult(
			suite: $suite,
			executed: true,
			exitCode: $aggregateExitCode,
			stdout: implode("\n\n", $stdoutChunks),
			stderr: implode("\n\n", $stderrChunks),
			failedLabels: $failedLabels,
		);
	}

	/**
	 * Returns the last $maxLines lines of $text, prefixed with a truncation note when trimmed.
	 */
	private static function tail(string $text, int $maxLines): string
	{
		if ($text === '')
		{
			return '';
		}

		$lines = explode("\n", $text);
		if (count($lines) <= $maxLines)
		{
			return $text;
		}

		return "… (output truncated to last {$maxLines} lines)\n" . implode("\n", array_slice($lines, -$maxLines));
	}

	/**
	 * @return list<string>
	 */
	public function buildCommand(AgentTestSuite $suite, string $testFile): array
	{
		return [
			PHP_BINARY,
			$suite->phpUnitPath,
			'--bootstrap',
			$suite->bootstrapPath,
			'--no-configuration',
			'--do-not-cache-result',
			$testFile,
		];
	}

	/**
	 * Returns why the suite cannot run (missing phpunit / bootstrap), or null when it can.
	 */
	private function missingPrerequisite(AgentTestSuite $suite): ?string
	{
		if (!is_file($suite->bootstrapPath))
		{
			return "PHPUnit bootstrap not found: {$suite->bootstrapPath}";
		}

		if (!is_file($suite->phpUnitPath))
		{
			return "PHPUnit binary not found: {$suite->phpUnitPath}";
		}

		return null;
	}

	/**
	 * @param list<string> $command
	 * @param array<string, string> $env
	 * @return array{exitCode:int, stdout:string, stderr:string}
	 */
	private function execute(array $command, array $env, string $workingDirectory): array
	{
		if ($this->executor !== null)
		{
			return ($this->executor)($command, $env, $workingDirectory);
		}

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open($command, $descriptors, $pipes, $workingDirectory, array_merge($_ENV, $env));
		if (!is_resource($process))
		{
			throw new \RuntimeException('Failed to start phpunit process for AI agent tests.');
		}

		fclose($pipes[0]);

		// Drain stdout and stderr concurrently. Reading one stream to EOF before the other deadlocks
		// as soon as phpunit fills the second pipe's OS buffer: the child blocks writing the full pipe
		// and never closes the first one, so the parent waits forever. Non-blocking reads driven by
		// stream_select keep both pipes moving until each reaches EOF.
		[$stdout, $stderr] = $this->drainPipes($pipes[1], $pipes[2]);

		fclose($pipes[1]);
		fclose($pipes[2]);

		$exitCode = proc_close($process);

		return [
			'exitCode' => (int)$exitCode,
			'stdout' => $stdout,
			'stderr' => $stderr,
		];
	}

	/**
	 * Reads two pipes to EOF concurrently so a large volume on one stream can never block the child
	 * process from writing — and eventually closing — the other.
	 *
	 * @param resource $stdoutPipe
	 * @param resource $stderrPipe
	 * @return array{0:string, 1:string} [stdout, stderr]
	 */
	private function drainPipes($stdoutPipe, $stderrPipe): array
	{
		$streams = [1 => $stdoutPipe, 2 => $stderrPipe];
		$buffers = [1 => '', 2 => ''];

		foreach ($streams as $stream)
		{
			stream_set_blocking($stream, false);
		}

		while ($streams !== [])
		{
			$read = array_values($streams);
			$write = null;
			$except = null;
			if (@stream_select($read, $write, $except, null) === false)
			{
				break;
			}

			foreach ($streams as $key => $stream)
			{
				if (!in_array($stream, $read, true))
				{
					continue;
				}

				$chunk = fread($stream, 8192);
				if ($chunk !== false && $chunk !== '')
				{
					$buffers[$key] .= $chunk;
				}

				if (feof($stream))
				{
					unset($streams[$key]);
				}
			}
		}

		return [$buffers[1], $buffers[2]];
	}
}
