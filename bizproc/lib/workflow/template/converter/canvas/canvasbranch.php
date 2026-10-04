<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasBranch
{
	private array $fragmentsByPortId = [];
	private array $portIds = [];
	private ?CanvasNode $mergeNode = null;

	public function __construct(
		private readonly string $nodeId,
	) {}

	public static function createForNode(string $nodeId): self
	{
		return new self($nodeId);
	}

	public function addPath(string $portId, ?CanvasFragment $fragment = null): self
	{
		$this->registerPort($portId);
		if ($fragment)
		{
			$this->fragmentsByPortId[$portId] = $fragment;
		}

		return $this;
	}

	public function registerPort(string $portId): self
	{
		if (!in_array($portId, $this->portIds, true))
		{
			$this->portIds[] = $portId;
		}

		return $this;
	}

	public function addFragment(string $portId, CanvasFragment $fragment): self
	{
		$this->registerPort($portId);
		$this->fragmentsByPortId[$portId] = $fragment;

		return $this;
	}

	public function findNodeId(): string
	{
		return $this->nodeId;
	}

	public function findFragmentsByPortId(): array
	{
		return $this->fragmentsByPortId;
	}

	public function findPortIds(): array
	{
		return !empty($this->portIds) ? $this->portIds : array_keys($this->fragmentsByPortId);
	}

	public function defineMergeNode(CanvasNode $mergeNode): self
	{
		$this->mergeNode = $mergeNode;

		return $this;
	}

	public function findMergeNode(): ?CanvasNode
	{
		return $this->mergeNode;
	}

	public function findFragmentByPortId(string $portId): ?CanvasFragment
	{
		return $this->fragmentsByPortId[$portId] ?? null;
	}
}
