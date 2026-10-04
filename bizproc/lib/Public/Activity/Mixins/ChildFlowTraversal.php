<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Mixins;

use Bitrix\Bizproc\Internal\Entity\Port\PortType;
use CBPActivity;
use CBPActivityExecutionResult;
use CBPActivityExecutionStatus;
use CBPArgumentNullException;

/**
 * The traversal of the children of a node: the queue of the children being executed, the transitions along
 * the links of the node and the closing of the node once the queue has drained.
 *
 * There is exactly one implementation of this for every surface - a complex node runs it from its own
 * execution ({@see \Bitrix\Bizproc\Public\Activity\Structure\FlowDirectedActivity}), a node served by the
 * unified panel runs it after its native logic. A second copy of the mechanics is what this composition
 * exists to prevent.
 *
 * Two points are left to the composing class:
 * - where the flow starts ({@see self::getStartActivityNames()}) and where it goes on from a child with no
 *   outgoing link ({@see self::onDeadEndReached()}), both defaulting to "nowhere";
 * - what an emptied queue means ({@see self::close()}), by default the closing of the node itself.
 */
trait ChildFlowTraversal
{
	protected const PARAM_LINKS = 'Links';
	public const LINK_DELIMITER = ':';
	public const LINK_SOURCE = 0;
	public const LINK_TARGET = 1;

	/** Children currently being executed, by name; the node stays open while it is not empty. */
	protected array $activityQueue = [];

	/** Successors waiting for a child that is already running, by the name of that child. */
	protected array $pendingQueue = [];

	/**
	 * Whether the flow of this execution of the node has already been started. An emptied queue and a flow
	 * that never ran look exactly alike, and the node that closes later has to tell them apart: it reaches
	 * its own closing twice - once when its own logic finishes, once when the queue has drained.
	 *
	 * Set here and not at the points of the call, so that a node whose whole execution is the flow marks
	 * itself as well and no second point ever restarts it. Reset with the node
	 * ({@see \CBPActivity::reInitialize()}), or a node run again in a loop would skip its children.
	 */
	protected bool $childFlowStarted = false;

	/**
	 * Starts the flow of the children.
	 *
	 * @return bool Whether anything really started: on false nothing was queued and the caller has nothing
	 *     to wait for.
	 * @throws CBPArgumentNullException
	 */
	protected function startChildFlow(): bool
	{
		$this->childFlowStarted = true;

		$startActivityNames = $this->getStartActivityNames();

		return $startActivityNames && $this->executeByNames($this, $startActivityNames);
	}

	/**
	 * Where the flow starts. The default is "nowhere", so composing this into a node that builds no children
	 * changes nothing for it.
	 *
	 * @return list<string>
	 */
	protected function getStartActivityNames(): array
	{
		return [];
	}

	/**
	 * Where the flow goes on from a child no link leads out of. The default ends the branch.
	 *
	 * @return list<string> Returns array of strings activity name with port like ['A1111_2222_3333_4444:i0']
	 */
	protected function onDeadEndReached(CBPActivity $lastActivity): array
	{
		return [];
	}

	/**
	 * @param CBPActivity $sender
	 * @param list<string> $names
	 * @return bool
	 * @throws CBPArgumentNullException
	 */
	protected function executeByNames(CBPActivity $sender, array $names): bool
	{
		foreach ($names as $activityName)
		{
			$inputPort = 0;
			$originalActivityName = $activityName;
			if (str_contains($activityName, $this->getInputPortPrefix()))
			{
				[$activityName, $inputPort] = explode($this->getInputPortPrefix(), $activityName);
				$inputPort = (int)$inputPort;
			}

			$activity = $this->workflow->getActivityByName($activityName);
			if (!$activity)
			{
				continue;
			}

			if (CBPActivityExecutionStatus::isInProgress($activity->executionStatus))
			{
				$this->subscribeActivity($activity);
				$this->pendingQueue[$activity->getName()][] = [$sender, [$originalActivityName]];

				continue;
			}

			$this->executeActivity($sender, $activity, $inputPort);
		}

		return !empty($this->activityQueue);
	}

	protected function executeActivity(CBPActivity $sender, CBPActivity $activity, int $inputPort): void
	{
		$this->subscribeActivity($activity);
		$activity->reInitialize();
		$payload = $this->workflow->executeActivity($activity);

		$payload
			->setParentName($sender->getName())
			->setParentPort($sender->getOutputPortId())
			->setInputPort($inputPort)
		;
	}

	private function subscribeActivity(CBPActivity $activity): void
	{
		if (!isset($this->activityQueue[$activity->getName()]))
		{
			$activity->addStatusChangeHandler(static::ClosedEvent, $this);
			$this->activityQueue[$activity->getName()] = true;
		}
	}

	public function onEvent(CBPActivity $sender, $arEventParameters = []): void
	{
		$sender->removeStatusChangeHandler(static::ClosedEvent, $this);
		unset($this->activityQueue[$sender->getName()]);

		if ($sender->executionResult === CBPActivityExecutionResult::Succeeded)
		{
			$next = $this->getOutputNames($sender->getName(), [$sender->getOutputPortId()]);
			if (!$next)
			{
				$next = $this->onDeadEndReached($sender);
			}

			if ($next)
			{
				$this->executeByNames($sender, $next);
			}
		}

		$this->executePendingQueue($sender->getName());

		if (empty($this->activityQueue))
		{
			$this->close();
		}
	}

	private function executePendingQueue($name)
	{
		$queue = $this->pendingQueue[$name] ?? [];
		unset($this->pendingQueue[$name]);

		foreach ($queue as [$sender, $names])
		{
			$this->executeByNames($sender, $names);
		}
	}

	/**
	 * The queue has drained. For a node whose whole execution is the flow of its children this is the closing
	 * of the node itself; a node with native logic of its own finishes its own postponed closing here.
	 */
	protected function close(): void
	{
		$this->workflow->closeActivity($this);
	}

	/**
	 * @param string $name
	 * @param array $outputIds
	 * @return list<string> Returns array of strings activity name with port like ['A1111_2222_3333_4444:i0']
	 */
	public function getOutputNames(string $name, array $outputIds = [0]): array
	{
		$links = $this->getLinks();

		$found = [];
		foreach ($outputIds as $outputId)
		{
			$haystack = [static::createOutputName($name, $outputId) => true];
			if ($outputId === 0)
			{
				$haystack[$name] = true;
			}

			$found[] = array_filter(
				$links,
				fn($link) =>
					isset($haystack[$link[self::LINK_SOURCE]])
					&& str_contains($link[self::LINK_TARGET], $this->getInputPortPrefix())
			);
		}

		return array_values(array_column(array_merge(...$found), self::LINK_TARGET));
	}

	/**
	 * Links between the children of the node. An absent property is no links at all: the mixin lives on the
	 * base activity, and a node whose properties were never completed with the graph of children must be
	 * read as childless instead of faulting the process.
	 */
	private function getLinks(): array
	{
		$links = $this->getRawProperty(self::PARAM_LINKS);

		return is_array($links) ? $links : [];
	}

	protected static function createOutputName(string $name, int $outputId): string
	{
		return self::composeLink($name, PortType::Output, $outputId);
	}

	/**
	 * @param string $name
	 * @param array $inputIds
	 *
	 * @return list<string> Returns array of strings activity name with port like ['A1111_2222_3333_4444:i0']
	 */
	public function getInputNames(string $name, array $inputIds = [0]): array
	{
		$links = $this->getLinks();

		$found = [];
		foreach ($inputIds as $inputId)
		{
			$haystack = [static::createInputName($name, $inputId) => true];
			if ($inputId === 0)
			{
				$haystack[$name] = true;
			}

			$found[] = array_filter(
				$links,
				fn($link) =>
					isset($haystack[$link[self::LINK_TARGET]])
					&& str_contains($link[self::LINK_SOURCE], $this->getOutputPrefix())
			);
		}

		return array_values(array_column(array_merge(...$found), self::LINK_SOURCE));
	}

	protected static function createInputName(string $name, int $inputId): string
	{
		return self::composeLink($name, PortType::Input, $inputId);
	}

	private function getInputPortPrefix(): string
	{
		return self::LINK_DELIMITER . PortType::Input->value;
	}

	private function getOutputPrefix(): string
	{
		return self::LINK_DELIMITER . PortType::Output->value;
	}

	private static function composeLink(string $name, PortType $portType, int $portId = 0): string
	{
		return $name . self::LINK_DELIMITER . $portType->value . $portId;
	}

	/**
	 * @param string $sourceActivityName
	 *
	 * @return list<string> Returns array of strings activity name with port like ['A1111_2222_3333_4444:i0']
	 */
	public function getAuxNames(string $sourceActivityName): array
	{
		$names = [];
		$auxPrefix = $sourceActivityName . self::LINK_DELIMITER . PortType::Aux->value;
		foreach ($this->getLinks() as $link)
		{
			if (!isset($link[self::LINK_SOURCE], $link[self::LINK_TARGET]))
			{
				continue;
			}

			$anotherActivityPort = null;
			[$sourceNodeLink, $targetNodeLink] = $link;
			if (str_starts_with($targetNodeLink, $auxPrefix))
			{
				$anotherActivityPort = $sourceNodeLink;
			}

			if (str_starts_with($sourceNodeLink, $auxPrefix))
			{
				$anotherActivityPort = $targetNodeLink;
			}

			if (!empty($anotherActivityPort))
			{
				$names[] = $anotherActivityPort;
			}
		}

		return $names;
	}

	/**
	 * @param string $activityNameWithPort like 'A1111_2222_3333_4444:i0'
	 *
	 * @return string|null 'A1111_2222_3333_4444'
	 */
	public function extractActivityNameFromLink(string $activityNameWithPort): ?string
	{
		$parts = explode(self::LINK_DELIMITER, $activityNameWithPort);

		return array_shift($parts);
	}

	/**
	 * @param list<string> $activityNamesWithPorts like ['A1111_2222_3333_4444:i0']
	 *
	 * @return list<string> like ['A1111_2222_3333_4444']
	 */
	public function extractActivityNamesFromLinks(array $activityNamesWithPorts): array
	{
		$names = [];
		foreach ($activityNamesWithPorts as $nameWithPort)
		{
			if (is_string($nameWithPort))
			{
				$name = $this->extractActivityNameFromLink($nameWithPort);
				if (!empty($name))
				{
					$names[] = $name;
				}
			}
		}

		return $names;
	}
}
