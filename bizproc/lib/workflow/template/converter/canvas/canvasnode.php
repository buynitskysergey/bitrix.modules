<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasNode
{
	private array $ports = [];
	private float $rowSpan = 1;
	private float $columnSpan = 1;

	public function __construct(
		private array $activity,
	) {}

	public static function createFromActivity(array $activity): self
	{
		return new self($activity);
	}

	public function definePorts(array $ports): self
	{
		$this->ports = $ports;

		return $this;
	}

	public function addPort(string $portId): self
	{
		$this->ports[] = $portId;

		return $this;
	}

	public function defineSize(float $rowSpan, float $columnSpan): self
	{
		$this->rowSpan = $rowSpan;
		$this->columnSpan = $columnSpan;

		return $this;
	}

	public function findId(): string
	{
		return $this->activity['Name'];
	}

	public function findActivity(): array
	{
		return $this->activity;
	}

	public function findPorts(): array
	{
		return $this->ports;
	}

	public function findRowSpan(): float
	{
		return $this->rowSpan;
	}

	public function findColumnSpan(): float
	{
		return $this->columnSpan;
	}

	public function createInputPortRef(string $portId = 'i0'): PortRef
	{
		return PortRef::createFromInputNode($this->findId(), $portId);
	}

	public function createOutputPortRef(string $portId = 'o0'): PortRef
	{
		return PortRef::createFromOutputNode($this->findId(), $portId);
	}

	public function createPortRef(string $portId): PortRef
	{
		return new PortRef($this->findId(), $portId);
	}
}
