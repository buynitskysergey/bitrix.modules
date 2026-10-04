<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Cli;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\AgentConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentTemplateGenerator;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentTestSuiteResolver;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentTestSuiteRunner;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\GeneratedFilesSnapshot;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates template.json, installer.php, and lang skeleton from template.source.json.
 *
 * After generation the agent test suite (bizproc/tests/nodes/AI_AGENT/<name>) is
 * executed; when it does not pass, the generated files are rolled back so the
 * working copy is never left with an unvalidated agent template. Pass
 * --skip-tests to generate without this validation.
 *
 * Usage: php bitrix/cli.php bizproc:ai-agent-generate <agent_name>
 * Example: php bitrix/cli.php bizproc:ai-agent-generate bitrix_ai_day_planner
 */
final class AiAgentGenerate extends Command
{
	public function isEnabled(): bool
	{
		return Loader::includeModule('bizproc');
	}

	protected function configure(): void
	{
		$this
			->setName('bizproc:ai-agent-generate')
			->setDescription('Generate AI agent template from template.source.json')
			->addArgument('name', InputArgument::REQUIRED, 'Agent directory name (e.g. bitrix_ai_day_planner)')
			->addOption(
				'skip-tests',
				null,
				InputOption::VALUE_NONE,
				'Generate without running the agent test suite (no post-generation validation and no rollback)',
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$agentName = $input->getArgument('name');
		if (!preg_match('/^[a-z0-9_]+$/D', $agentName))
		{
			$io->error("Invalid agent name: only lowercase letters, digits and underscores allowed");

			return self::FAILURE;
		}

		$nodesDir = $this->getNodesDir();
		$agentDir = $nodesDir . '/' . $agentName;
		if (!Directory::isDirectoryExists($agentDir))
		{
			$io->error("Agent directory not found: {$agentDir}");

			return self::FAILURE;
		}

		$configPath = $agentDir . '/template.source.json';
		if (!File::isFileExists($configPath))
		{
			$io->error("Config file not found: {$configPath}");

			return self::FAILURE;
		}

		$skipTests = (bool)$input->getOption('skip-tests');

		$resolver = new AgentTestSuiteResolver($nodesDir);
		$suite = $resolver->resolve($agentName);
		$runTests = !$skipTests && $suite->hasTests();

		try
		{
			// The snapshot serves the rollback of the test gate and nothing else, so it is taken when that gate
			// will run and before the first write - the only moment it can be taken at. The window of the two
			// writes belongs to generation, which snapshots the same two files itself; refusing a generation
			// whose template.json cannot be read is that snapshot's doing, with this one or without it.
			$snapshot = null;
			if ($runTests)
			{
				$snapshot = GeneratedFilesSnapshot::capture([
					$agentDir . '/template.json',
					$agentDir . '/installer.php',
				]);
			}

			$generator = new AgentTemplateGenerator($nodesDir);
			$generatedFiles = $generator->generate($agentName);
		}
		catch (\Throwable $e)
		{
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$warnings = array_merge(
			$this->getPartialSourceWarnings($agentName, $configPath),
			$generator->getPropertyWarnings(),
			$generator->getDescriptorWarnings(),
		);
		foreach ($warnings as $warning)
		{
			$io->warning($warning);
		}

		$testsSummaryLine = '  - no AI-agent tests found';
		if ($skipTests)
		{
			$testsSummaryLine = '  - tests skipped (--skip-tests)';
		}
		elseif ($runTests)
		{
			$io->writeln(sprintf(
				'Running %d AI-agent test file(s) for %s...',
				count($suite->testFiles),
				$agentName,
			));

			$testResult = null;
			try
			{
				$testResult = (new AgentTestSuiteRunner($resolver))->runSuite($suite, $this->getDocumentRoot());
			}
			catch (\Throwable $e)
			{
				// Being unable to run the suite is not a generation failure: keep the files, just warn.
				$io->warning("Agent '{$agentName}': test suite could not run and was skipped — " . $e->getMessage());
				$testsSummaryLine = '  - tests skipped (could not run)';
			}

			if ($testResult !== null && $testResult->skippedReason !== null)
			{
				// e.g. phpunit / bootstrap not available in this environment — skip, don't roll back.
				$io->warning("Agent '{$agentName}': test suite skipped — " . $testResult->skippedReason);
				$testsSummaryLine = '  - tests skipped (test runtime unavailable)';
			}
			elseif ($testResult !== null && !$testResult->isSuccessful())
			{
				$error = [
					sprintf(
						"Agent '%s' was generated, but %d of %d test file(s) failed.",
						$agentName,
						count($testResult->failedLabels),
						count($suite->testFiles),
					),
				];

				// The gate ran, so the snapshot of this run is here - and the rollback says how it went.
				if ($snapshot !== null)
				{
					$error[] = $this->rollBack($snapshot);
				}

				if ($testResult->failedLabels !== [])
				{
					$error[] = 'Failed: ' . implode(', ', $testResult->failedLabels);
				}

				$stdout = trim($testResult->stdout);
				if ($stdout !== '')
				{
					$error[] = '--- failing output (tail per file) ---';
					$error[] = $stdout;
				}

				$stderr = trim($testResult->stderr);
				if ($stderr !== '')
				{
					$error[] = '--- stderr (tail per file) ---';
					$error[] = $stderr;
				}

				$error[] = 'Re-run the suite for full output, or pass --skip-tests to generate without it.';

				$io->error($error);

				return self::FAILURE;
			}
			elseif ($testResult !== null)
			{
				$testsSummaryLine = sprintf('  - validated %d AI-agent test file(s)', count($suite->testFiles));
			}
		}

		$message = ["Agent '{$agentName}' generated successfully:"];
		foreach ($generatedFiles as $file)
		{
			$message[] = "  - {$file}";
		}
		$message[] = $testsSummaryLine;

		$io->success($message);

		return self::SUCCESS;
	}

	/**
	 * Takes the generated files back to the state they were delivered in, and says how that went: a
	 * rollback reported as done while a file stayed as generation wrote it would leave the working copy
	 * holding a template the tests have just rejected, with nobody looking for it.
	 */
	private function rollBack(GeneratedFilesSnapshot $snapshot): string
	{
		try
		{
			$snapshot->restore();
		}
		catch (\Throwable $e)
		{
			return $e->getMessage();
		}

		return 'Generated files were rolled back.';
	}

	/**
	 * A source the reverse wrote with '--allow-lossy' describes its agent in part only: the template it was
	 * reversed from held constructs the round trip did not carry over, and generating from such a source
	 * overwrites the delivered template with the part that survived (TPL-06). The generation itself goes
	 * through - the author asked for this source knowingly - but never silently.
	 *
	 * @return list<string>
	 */
	private function getPartialSourceWarnings(string $agentName, string $configPath): array
	{
		try
		{
			$isPartial = AgentConfig::fromFile($configPath)->isPartial;
		}
		catch (\Throwable)
		{
			// The generator parses the same file and reports what is wrong with it.
			return [];
		}

		if (!$isPartial)
		{
			return [];
		}

		return [sprintf(
			"Agent '%s': template.source.json is marked with \"_partial\": true - the reverse wrote it with"
			. ' --allow-lossy, so it does not describe the agent in full. Compare the generated template.json'
			. ' with the previous one before delivering it, and remove the mark once the source is complete.',
			$agentName,
		)];
	}

	private function getDocumentRoot(): string
	{
		return (string)\Bitrix\Main\Application::getInstance()
			->getContext()
			->getServer()
			->getDocumentRoot();
	}

	private function getNodesDir(): string
	{
		$documentRoot = $this->getDocumentRoot();

		return $documentRoot . '/bitrix/modules/bizproc/nodes/AI_AGENT';
	}
}
