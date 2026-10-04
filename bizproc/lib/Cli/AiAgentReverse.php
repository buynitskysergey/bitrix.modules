<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Cli;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser\EquivalenceReport;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser\TemplateReverser;
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
 * Reverses an existing template.json back into a template.source.json.
 *
 * The round trip is checked before anything is written: the reversed source is built back in memory and
 * compared with the template it came from, and only an equivalent result reaches the disk (TPL-06). Writing
 * a source that builds into another template would leave the agent to be regenerated from it later.
 *
 * Usage: php bitrix/cli.php bizproc:ai-agent-reverse <agent_name> [--force] [--verify] [--allow-lossy]
 */
final class AiAgentReverse extends Command
{
	public function isEnabled(): bool
	{
		return Loader::includeModule('bizproc');
	}

	protected function configure(): void
	{
		$this
			->setName('bizproc:ai-agent-reverse')
			->setDescription('Reverse-engineer template.source.json from template.json')
			->addArgument('name', InputArgument::REQUIRED, 'Agent directory name (e.g. bitrix_ai_project_pulse)')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing template.source.json')
			->addOption(
				'verify',
				null,
				InputOption::VALUE_NONE,
				'Report the round trip check even when it has nothing to note; its notes are printed either way',
			)
			->addOption(
				'allow-lossy',
				null,
				InputOption::VALUE_NONE,
				'Write the source even when the round trip is not equivalent, marking it with "_partial": true',
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$agentName = (string)$input->getArgument('name');
		if (!preg_match('/^[a-z0-9_]+$/D', $agentName))
		{
			$io->error('Invalid agent name: only lowercase letters, digits and underscores allowed');

			return self::FAILURE;
		}

		$nodesDir = $this->getNodesDir();
		$agentDir = $nodesDir . '/' . $agentName;
		if (!Directory::isDirectoryExists($agentDir))
		{
			$io->error("Agent directory not found: {$agentDir}");

			return self::FAILURE;
		}

		$templatePath = $agentDir . '/template.json';
		if (!File::isFileExists($templatePath))
		{
			$io->error("template.json not found: {$templatePath}");

			return self::FAILURE;
		}

		$sourcePath = $agentDir . '/template.source.json';
		if (File::isFileExists($sourcePath) && !$input->getOption('force'))
		{
			$io->error("template.source.json already exists: {$sourcePath}. Use --force to overwrite.");

			return self::FAILURE;
		}

		$allowLossy = (bool)$input->getOption('allow-lossy');

		try
		{
			$raw = File::getFileContents($templatePath);
			if (!is_string($raw))
			{
				$io->error("Failed to read {$templatePath}");

				return self::FAILURE;
			}
			$template = \Bitrix\Main\Web\Json::decode($raw);
			if (!is_array($template))
			{
				$io->error("template.json must decode to an object: {$templatePath}");

				return self::FAILURE;
			}

			$reverser = new TemplateReverser(new ActivityRegistry());
			$source = $reverser->reverse($template, $agentName);
			$report = $reverser->verifyRoundTrip($template, $source);
		}
		catch (\Throwable $e)
		{
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$description = $report->describe();
		$isPartial = !$report->isClean();

		if ($isPartial && !$allowLossy)
		{
			$io->error(array_merge([$this->describeRefusal($agentName)], $description));

			return self::FAILURE;
		}

		if ($isPartial)
		{
			// The mark travels with the source: 'generate' warns about it, and a reader of the file sees at
			// the first line that the agent is no longer described by it in full.
			$source = ['_partial' => true] + $source;
		}

		if (!$this->writeSource($io, $sourcePath, $source))
		{
			return self::FAILURE;
		}

		if ($isPartial)
		{
			$io->warning(implode("\n", array_merge(
				[
					"Agent '{$agentName}' reversed into a partial source (--allow-lossy):",
					"  - {$sourcePath}",
					'The source is marked with "_partial": true - generating the agent from it would not restore'
						. ' the template below in full.',
				],
				$description,
			)));

			return self::SUCCESS;
		}

		$io->success([
			"Agent '{$agentName}' reversed successfully:",
			"  - {$sourcePath}",
			'Round trip verified: the template built back from this source is equivalent to template.json.',
		]);

		// Notes of a clean report - dangling links of the original, the wizard the build restores approximately -
		// are no drift of the round trip, so they are printed as what they are and read through the warning
		// below. '--verify' asks for the report of the check even when it has none of them to show.
		if ($description !== [])
		{
			$io->text($description);
		}
		elseif ($input->getOption('verify'))
		{
			$io->text('The round trip changed nothing at all: no dangling links, no approximated properties.');
		}

		$io->warning($this->describeSourceWarning($agentName, $report));

		return self::SUCCESS;
	}

	/**
	 * What the source just written means for the person about to commit it. Stands after the report on purpose:
	 * the notes printed above are read through this.
	 *
	 * The notes are told apart because they cost different things. An approximately restored wizard changes how
	 * the agent is installed, so such a source is no source of a shipped agent yet. A dangling link leads nowhere
	 * in the template either, and the canvas the generator lays out anew is no part of what the agent does - both
	 * leave the source usable. Usable is not free, though: the price is named in every case below, because a
	 * regenerated template.json moves the revision of the agent whatever the report says.
	 */
	private function describeSourceWarning(string $agentName, EquivalenceReport $report): string
	{
		$lines = [];

		if ($report->approximated !== [])
		{
			$lines[] = "Do not commit this template.source.json as the source of the shipped agent '{$agentName}':"
				. ' the wizard of the setup listed above as approximated comes back organized in a shape of its'
				. ' own - its blocks begin elsewhere, and a title, a description or a separator of it may be gone.'
				. ' What the wizard asks of the person setting the agent up is carried over exactly - which field'
				. ' has to be filled in and what with - but that person would see another wizard. Read this source'
				. ' and work out what the note is about, but keep shipping the agent from the template.json it came'
				. ' from until the reverse carries its wizard over with nothing to note.';
		}
		elseif ($report->danglingLinks !== [])
		{
			$lines[] = 'The dangling links above are no reason to keep this template.source.json out of the'
				. " repository as the source of '{$agentName}': an end of such a link is no node of the template,"
				. ' so the link leads nowhere there either and no build would write it back.';
		}

		$lines[] = 'Generating the agent from this source writes template.json anew, and only what the format'
			. ' states reaches the file: the nodes are laid out on a grid by the generator, and what was drawn'
			. ' around them - the position of a node, an empty comment of the designer, the service fields of the'
			. ' canvas - is not written back. So unless the shipped template.json came from this generator in the'
			. ' first place, its bytes move, and the revision of the agent - a hash of the whole shipped template -'
			. ' moves with them: every running copy of the agent is then offered an update, and confirming it'
			. ' overwrites what was set up on the portal. Generate the agent once and read the diff of'
			. ' template.json before committing either file.';

		return implode("\n", $lines);
	}

	/**
	 * Why a source is not written: what the round trip would lose, and the two ways out - what the reverse or
	 * the format has to learn, or the partial result has to be asked for explicitly.
	 */
	private function describeRefusal(string $agentName): string
	{
		return "Agent '{$agentName}' was not reversed: the template built back from the reversed source is not"
			. ' equivalent to template.json, so template.source.json was not written. An entry below names either'
			. ' the node it is about - a lost node or link, a property, a node descriptor - or the data of the'
			. ' template beside its nodes: its NAME, its DESCRIPTION and the fields of a parameter, a variable or'
			. ' a constant, under "Data of the template beside its nodes that differs". Whichever it is, the'
			. " reverse does not carry it yet - except under 'constructs the source format cannot express', where"
			. ' the format itself has no way to write the construct down. Teach the reverse what it drops, teach'
			. ' the format what it cannot state, or pass --allow-lossy to write the partial source anyway - it is'
			. ' then marked with "_partial": true and generating the agent from it will not restore this template'
			. ' in full.';
	}

	/**
	 * @param array<string, mixed> $source
	 *
	 * @return bool false when the source did not reach the disk in full, the error is already reported
	 */
	private function writeSource(SymfonyStyle $io, string $sourcePath, array $source): bool
	{
		try
		{
			$contents = \Bitrix\Main\Web\Json::encode(
				$source,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
			) . PHP_EOL;
			$written = File::putFileContents($sourcePath, $contents);
		}
		catch (\Throwable $e)
		{
			$io->error($e->getMessage());

			return false;
		}

		// Every byte has to be on the disk: a write that stops halfway - no space left on the device, a quota
		// reached - answers with the number of bytes it managed and not with false, and a truncated
		// template.source.json is no source of an agent at all.
		$length = strlen($contents);
		if ($written !== $length)
		{
			$io->error(sprintf(
				'Failed to write template.source.json: %s - %s of %d bytes written',
				$sourcePath,
				$written === false ? 'none' : (string)$written,
				$length,
			));

			return false;
		}

		return true;
	}

	private function getNodesDir(): string
	{
		$documentRoot = (string)\Bitrix\Main\Application::getInstance()
			->getContext()
			->getServer()
			->getDocumentRoot();

		return $documentRoot . '/bitrix/modules/bizproc/nodes/AI_AGENT';
	}
}
