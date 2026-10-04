<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\AgentConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityNodeBuilder;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\DescriptorRegistryDrift;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\TemplateBuilder;
use Bitrix\Main\IO\File;

final class AgentTemplateGenerator
{
	private string $nodesBasePath;
	private array $propertyWarnings = [];
	/** @var list<string> */
	private array $descriptorWarnings = [];

	public function __construct(string $nodesBasePath)
	{
		$this->nodesBasePath = rtrim($nodesBasePath, '/');
	}

	public function getPropertyWarnings(): array
	{
		return $this->propertyWarnings;
	}

	/**
	 * What the last generation has to say about the visual descriptors of template.source.json: activities
	 * this environment cannot describe at all, and descriptors that disagree with the description they
	 * were once taken from. The descriptor is a snapshot taken for the sake of a reproducible build, while
	 * the truth about how a node looks belongs to the activity description of the owning module - so a
	 * build either checks the snapshot against that description or says that it could not.
	 *
	 * Warnings only: a node backed by a complete descriptor is written as is, and the descriptor keeps
	 * winning over the registry. The refusal lives in {@see self::checkNodesAreTrustworthy()} and covers
	 * the other case, where neither source nor registry has the data.
	 *
	 * @return list<string>
	 */
	public function getDescriptorWarnings(): array
	{
		return $this->descriptorWarnings;
	}

	/**
	 * Generation is all or nothing: when this throws, the delivered files of the agent are as they were.
	 * Every refusal is settled before the first write, and a write that fails is rolled back.
	 *
	 * @return string[] List of changed file paths
	 */
	public function generate(string $agentName): array
	{
		if (!preg_match('/^[a-z0-9_]+$/D', $agentName))
		{
			throw new \InvalidArgumentException("Invalid agent name: '$agentName'. Only lowercase letters, digits and underscores allowed.");
		}

		$agentDir = $this->nodesBasePath . '/' . $agentName;
		$configPath = $agentDir . '/template.source.json';

		$config = AgentConfig::fromFile($configPath);

		$errors = $config->validate();
		if (!empty($errors))
		{
			throw new \RuntimeException('Config validation failed: ' . implode('; ', $errors));
		}

		$registry = new ActivityRegistry();

		$validator = new ConfigValidator($registry);
		$this->propertyWarnings = $validator->validate($config);
		$nodeBuilder = new ActivityNodeBuilder($registry);
		$templateBuilder = new TemplateBuilder($registry, $nodeBuilder);
		$template = $templateBuilder->build($config);
		$this->checkNodesAreTrustworthy(
			$agentName,
			$nodeBuilder->getUntrustedActivityCounts(),
			$nodeBuilder->getUntrustedInnerActivityCounts(),
		);

		$drift = (new DescriptorRegistryDrift($registry))->describe($config->activityDescriptors);
		$this->descriptorWarnings = array_values(array_filter([
			$this->describeUnverifiableDescriptors($agentName, $nodeBuilder->getDescriptorOnlyActivityCounts()),
			$this->describeDescriptorDrift($agentName, $drift),
		]));

		$templateJson = \Bitrix\Main\Web\Json::encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		$templatePath = $agentDir . '/template.json';
		$installerPath = $agentDir . '/installer.php';

		// Nothing about installer.php is left to be refused once template.json is written: a template
		// written next to an installer that then turns out to carry no timestamp marker would leave the
		// delivered agent describing a new version of itself under the old one.
		$installerContents = $this->buildUpdatedInstaller($installerPath);

		$this->writeGeneratedFiles([
			$templatePath => $templateJson . PHP_EOL,
			$installerPath => $installerContents,
		]);

		return [
			$templatePath,
			$installerPath,
		];
	}

	/**
	 * Activities whose nodes were built from the source descriptor alone, aggregated by type: naming every
	 * node would mean dozens of lines about one missing module.
	 *
	 * @param array<string, int> $descriptorOnlyCounts activity type => node count
	 * @return string|null null when every activity of the build resolves here
	 */
	private function describeUnverifiableDescriptors(string $agentName, array $descriptorOnlyCounts): ?string
	{
		$listed = $this->listSubjectCounts($descriptorOnlyCounts);
		if ($listed === [])
		{
			return null;
		}

		return sprintf(
			"Agent '%s': some activities do not resolve in this environment, so the node type, icon, color"
			. " index, size and ports of their nodes were taken from 'activity_descriptors' in"
			. ' template.source.json and could not be checked against the description of the activity.'
			. ' Affected: %s.'
			. ' The build stays reproducible here only because the source states those fields; install the'
			. ' module that owns the activity to have them verified against its description.',
			$agentName,
			implode('; ', $listed),
		);
	}

	/**
	 * Descriptors that no longer say what the activity description of this environment says. Only a
	 * warning: the shipped template must keep following the source, or it would change on its own as soon
	 * as a foreign module is updated.
	 *
	 * @param list<string> $differences
	 * @return string|null null when every stated descriptor field matches the description it came from
	 */
	private function describeDescriptorDrift(string $agentName, array $differences): ?string
	{
		if (empty($differences))
		{
			return null;
		}

		return sprintf(
			"Agent '%s': the node descriptors of template.source.json disagree with the activity"
			. ' descriptions of this environment: %s.'
			. ' The descriptor wins, so the generated template carries the values of the source.'
			. " Update 'activity_descriptors' if the description is the newer truth, and leave it as it is"
			. ' if the node of the delivered agent is meant to differ from its activity.',
			$agentName,
			implode('; ', $differences),
		);
	}

	/**
	 * Writing template.json is the only irreversible step of generation, so a build that invented the
	 * visual part of a node is refused before it: the delivered template would otherwise be rewritten
	 * with values that depend on which modules this environment happens to have.
	 *
	 * The two kinds of untrustworthy node get their own answer, and the builder tells them apart for us. A
	 * node of an unresolved activity misses the fields of the visual descriptor, and a descriptor in the
	 * source states them. An inner activity of a complex wrapper misses its ReturnProperties, which no
	 * descriptor covers (TPL-03) - there the source can only carry the whole activity, rules and return
	 * properties included, the way a reversed source does.
	 *
	 * @param array<string, int> $untrustedActivityCounts activity type => node count
	 * @param array<string, int> $untrustedInnerActivityCounts 'outer type (inner activity type)' => node count
	 */
	private function checkNodesAreTrustworthy(
		string $agentName,
		array $untrustedActivityCounts,
		array $untrustedInnerActivityCounts,
	): void
	{
		$nodes = $this->listSubjectCounts($untrustedActivityCounts);
		$innerActivities = $this->listSubjectCounts($untrustedInnerActivityCounts);
		if ($nodes === [] && $innerActivities === [])
		{
			return;
		}

		$reasons = [];
		if ($nodes !== [])
		{
			$reasons[] = sprintf(
				'some activities do not resolve in this environment and have no descriptor in'
				. ' template.source.json, so their icon, color, size and ports would be replaced with generic'
				. " defaults (%s) - add each type to 'activity_descriptors' in template.source.json, or install"
				. ' the module that owns the activity',
				implode('; ', $nodes),
			);
		}
		if ($innerActivities !== [])
		{
			$reasons[] = sprintf(
				'some activities a complex node runs inside itself do not resolve in this environment, so their'
				. ' return properties would be built empty (%s) - a node descriptor does not cover return'
				. ' properties, so either install the module that owns the inner activity or write the whole'
				. " inner activity into the 'Rules' of the step, its 'ReturnProperties' included",
				implode('; ', $innerActivities),
			);
		}

		throw new \RuntimeException(sprintf(
			"Agent '%s' was not generated: %s. No files were written.",
			$agentName,
			implode('. Also, ', $reasons),
		));
	}

	/**
	 * @param array<string, int> $counts subject => node count
	 * @return list<string>
	 */
	private function listSubjectCounts(array $counts): array
	{
		$listed = [];
		foreach ($counts as $subject => $nodeCount)
		{
			$listed[] = "{$subject}: {$nodeCount} node(s)";
		}

		return $listed;
	}

	/**
	 * The generated installer.php differs from the delivered one in its timestamp alone - it is what tells
	 * a portal that the shipped agent is newer than the installed copy. Built without writing anything, so
	 * that every reason to refuse it is known before the first file is touched.
	 */
	private function buildUpdatedInstaller(string $path): string
	{
		if (!File::isFileExists($path))
		{
			throw new \RuntimeException("installer.php not found: {$path}. Create it before running the generator.");
		}

		$contents = File::getFileContents($path);
		if (!is_string($contents))
		{
			throw new \RuntimeException("Failed to read file: {$path}");
		}

		$mtime = time();
		$updated = preg_replace(
			'|/\*mtime\*/\d+/\*mtime\*/|',
			"/*mtime*/{$mtime}/*mtime*/",
			$contents,
			1,
			$count,
		);
		if ($count === 0)
		{
			throw new \RuntimeException("installer.php does not contain /*mtime*/.../*mtime*/ marker: {$path}");
		}

		return (string)$updated;
	}

	/**
	 * The generated files are written as one step. A write that fails halfway - the second file, or a
	 * truncated first one - would leave the delivered agent described by one file and versioned by the
	 * other, so what was there before is put back and the failure is reported instead.
	 *
	 * A write is done only when every byte of the contents is on the disk: a write that stops halfway - no space
	 * left on the device, a quota reached - answers with the number of bytes it managed and not with false, and a
	 * truncated template.json read as a success would be delivered as the template of the agent.
	 *
	 * A write can also throw instead of answering at all - a warning of the file system turned into an exception
	 * by the error handler of the environment, a directory that cannot be created - and one step of writing means
	 * the same answer to every failure: whatever leaves the loop rolls the delivered files back and is reported
	 * together with what the rollback did.
	 *
	 * @param array<string, string> $files path => contents
	 */
	private function writeGeneratedFiles(array $files): void
	{
		$snapshot = GeneratedFilesSnapshot::capture(array_keys($files));

		try
		{
			foreach ($files as $path => $contents)
			{
				$written = File::putFileContents($path, $contents);
				if ($written === strlen($contents))
				{
					continue;
				}

				throw new \RuntimeException(sprintf(
					'Failed to write file: %s - %s of %d bytes written',
					$path,
					$written === false ? 'none' : (string)$written,
					strlen($contents),
				));
			}
		}
		catch (\Throwable $failure)
		{
			throw new \RuntimeException(
				sprintf('%s. %s', rtrim($failure->getMessage(), ' .'), $this->rollBack($snapshot)),
				previous: $failure,
			);
		}
	}

	/** @return string what the rollback did, to be told together with the failure that called for it */
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

		return 'The previously delivered files were restored.';
	}
}

