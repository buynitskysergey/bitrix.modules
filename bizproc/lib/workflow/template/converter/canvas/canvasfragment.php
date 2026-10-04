<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasFragment
{
	private array $nodes = [];
	private array $links = [];
	private array $frames = [];
	private array $branches = [];
	private ?CanvasContainer $rootContainer = null;
	private ?PortRef $exitPort = null;

	public static function create(): self
	{
		return new self();
	}

	public static function createFromActivities(array $activities): self
	{
		return self::create()->addActivities($activities);
	}

	public static function createFromNodes(array $nodes): self
	{
		$fragment = self::create();
		foreach ($nodes as $node)
		{
			if ($node instanceof CanvasNode)
			{
				$fragment->addNode($node);
			}
		}

		return $fragment;
	}

	public function addActivity(array $activity): CanvasNode
	{
		$node = CanvasNode::createFromActivity($activity);
		$this->addNode($node);

		return $node;
	}

	public function addActivities(array $activities): self
	{
		foreach ($activities as $activity)
		{
			$this->addActivity($activity);
		}

		return $this;
	}

	public function addNode(CanvasNode $node): self
	{
		$this->nodes[$node->findId()] = $node;

		return $this;
	}

	public function addLink(CanvasLink $link): self
	{
		$this->links[] = $link;

		return $this;
	}

	public function addLinkBetween(string $sourceNodeId, string $targetNodeId): self
	{
		return $this->addLink(CanvasLink::createBetween($sourceNodeId, $targetNodeId));
	}

	public function linkNodes(CanvasNode $source, CanvasNode $target): self
	{
		return $this->addLink(new CanvasLink(
			$source->createOutputPortRef(),
			$target->createInputPortRef(),
		));
	}

	public function addFrame(CanvasFrame $frame): self
	{
		$this->frames[$frame->findId()] = $frame;

		return $this;
	}

	public function addBranch(CanvasBranch $branch): self
	{
		$this->branches[$branch->findNodeId()] = $branch;

		return $this;
	}

	public function appendFragment(self $fragment): self
	{
		foreach ($fragment->findNodes() as $node)
		{
			$this->addNode($node);
		}

		foreach ($fragment->findLinks() as $link)
		{
			$this->addLink($link);
		}

		foreach ($fragment->findFrames() as $frame)
		{
			$this->addFrame($frame);
		}

		foreach ($fragment->findBranches() as $branch)
		{
			$this->addBranch($branch);
		}

		if ($fragment->findExitPort())
		{
			$this->defineExitPort($fragment->findExitPort());
		}

		return $this;
	}

	public function appendFragmentAfterExit(self $fragment): self
	{
		$entryNode = $fragment->findFirstNode();
		if ($this->exitPort && $entryNode)
		{
			$this->addLink(new CanvasLink(
				$this->exitPort,
				$entryNode->createInputPortRef(),
			));
		}

		return $this->appendFragment($fragment);
	}

	public function appendActivity(array $activity): CanvasNode
	{
		$node = $this->addActivity($activity);

		if ($this->exitPort)
		{
			$this->addLink(new CanvasLink(
				$this->exitPort,
				$node->createInputPortRef(),
			));
		}

		$this->defineExitPort($node->createOutputPortRef());

		return $node;
	}

	public function appendNode(CanvasNode $node): CanvasNode
	{
		$this->addNode($node);

		if ($this->exitPort)
		{
			$this->addLink(new CanvasLink(
				$this->exitPort,
				$node->createInputPortRef(),
			));
		}

		$this->defineExitPort($node->createOutputPortRef());

		return $node;
	}

	public function wrapInFrame(string $frameId, ?string $title = null): CanvasFrame
	{
		$frame = CanvasFrame::create($frameId, $title)->wrapFragment($this);
		$this->addFrame($frame);

		return $frame;
	}

	public function defineRootContainer(?CanvasContainer $container): self
	{
		$this->rootContainer = $container;

		return $this;
	}

	public function defineExitPort(?PortRef $exitPort): self
	{
		$this->exitPort = $exitPort;

		return $this;
	}

	public function findExitPort(): ?PortRef
	{
		return $this->exitPort;
	}

	public function findRootContainer(): ?CanvasContainer
	{
		return $this->rootContainer;
	}

	public function findNodes(): array
	{
		return array_values($this->nodes);
	}

	public function findNodeIds(): array
	{
		return array_keys($this->nodes);
	}

	public function findFirstNode(): ?CanvasNode
	{
		return reset($this->nodes) ?: null;
	}

	public function findLinks(): array
	{
		return $this->links;
	}

	public function findFrames(): array
	{
		return array_values($this->frames);
	}

	public function findBranches(): array
	{
		return array_values($this->branches);
	}

	public function findBranchByNodeId(string $nodeId): ?CanvasBranch
	{
		return $this->branches[$nodeId] ?? null;
	}
}
