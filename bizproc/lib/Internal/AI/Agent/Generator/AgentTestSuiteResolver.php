<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AgentTestSuiteResolver
{
	public function __construct(
		private readonly string $nodesBasePath,
	)
	{
	}

	public function resolve(string $agentName): AgentTestSuite
	{
		if (!preg_match('/^[a-z0-9_]+$/D', $agentName))
		{
			throw new \InvalidArgumentException("Invalid agent name: '$agentName'.");
		}

		$resolvedNodesBasePath = realpath($this->nodesBasePath);
		$moduleRoot = dirname($resolvedNodesBasePath !== false ? $resolvedNodesBasePath : $this->nodesBasePath, 2);
		$repositoryRoot = dirname($moduleRoot);
		$testsDirectory = $moduleRoot . '/tests/nodes/AI_AGENT/' . $agentName;

		return new AgentTestSuite(
			agentName: $agentName,
			testsDirectory: $testsDirectory,
			testFiles: $this->collectTestFiles($testsDirectory),
			bootstrapPath: $moduleRoot . '/tests/bootstrap.php',
			phpUnitPath: $repositoryRoot . '/vendor/bin/phpunit',
		);
	}

	/**
	 * @return list<string>
	 */
	private function collectTestFiles(string $testsDirectory): array
	{
		if (!is_dir($testsDirectory))
		{
			return [];
		}

		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($testsDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
		);

		foreach ($iterator as $fileInfo)
		{
			if (!$fileInfo->isFile())
			{
				continue;
			}

			$path = $fileInfo->getPathname();
			if (preg_match('/Test\.php$/', $path) === 1)
			{
				$files[] = $path;
			}
		}

		sort($files);

		return $files;
	}
}
