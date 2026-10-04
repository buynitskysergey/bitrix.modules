<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Layout;

final class LayoutResult
{
	public array $children = [];
	public array $links = [];
	public array $anchors = [];
	public array $nodeFrames = [];

	public function __construct(
		public string $exitId,
		public GridFrame $frame,
	) {}

	public static function createFromAnchor(string $id, GridPoint $point): self
	{
		$result = new self($id, GridFrame::createFromPoint($point));
		$result->anchors[$id] = $point;

		return $result;
	}

	public static function createFromNode(array $activity, GridPoint $point, ?GridFrame $frame = null): self
	{
		$result = self::createFromAnchor($activity['Name'], $point);
		$result->children[] = $activity;
		$result->nodeFrames[$activity['Name']] = $frame ?? GridFrame::createFromPoint($point);
		$result->frame = $result->nodeFrames[$activity['Name']];

		return $result;
	}

	public function appendLayout(self $layout): self
	{
		$this->children = [...$this->children, ...$layout->children];
		$this->links = [...$this->links, ...$layout->links];
		$this->anchors = [...$this->anchors, ...$layout->anchors];
		$this->nodeFrames = [...$this->nodeFrames, ...$layout->nodeFrames];
		$this->frame = GridFrame::merge($this->frame, $layout->frame);

		return $this;
	}

	public function addLink(string $outputName, string $inputName): self
	{
		$this->links[] = [
			$this->addDefaultOutputPort($outputName),
			$this->addDefaultInputPort($inputName),
		];

		return $this;
	}

	private function addDefaultOutputPort(string $nodeId): string
	{
		return str_contains($nodeId, ':') ? $nodeId : "{$nodeId}:o0";
	}

	private function addDefaultInputPort(string $nodeId): string
	{
		return str_contains($nodeId, ':') ? $nodeId : "{$nodeId}:i0";
	}

	public function addAnchor(string $id, GridPoint $point): self
	{
		$this->anchors[$id] = $point;
		$this->frame = GridFrame::merge($this->frame, GridFrame::createFromPoint($point));

		return $this;
	}

	public function addNodeFrame(string $id, GridFrame $frame): self
	{
		$this->nodeFrames[$id] = $frame;
		$this->frame = GridFrame::merge($this->frame, $frame);

		return $this;
	}

	public function moveBy(float $rowShift, float $columnShift): self
	{
		foreach ($this->anchors as $id => $point)
		{
			$this->anchors[$id] = $point->moveBy($rowShift, $columnShift);
		}

		foreach ($this->nodeFrames as $id => $frame)
		{
			$this->nodeFrames[$id] = $frame->moveBy($rowShift, $columnShift);
		}

		$this->frame = $this->frame->moveBy($rowShift, $columnShift);

		return $this;
	}

	public function moveNodesByNames(array $names, float $rowShift, float $columnShift): self
	{
		$nameMap = array_fill_keys($names, true);

		foreach ($this->anchors as $id => $point)
		{
			$nodeName = explode(':', $id, 2)[0];
			if (isset($nameMap[$nodeName]))
			{
				$this->anchors[$id] = $point->moveBy($rowShift, $columnShift);
			}
		}

		foreach ($this->nodeFrames as $id => $frame)
		{
			if (isset($nameMap[$id]))
			{
				$this->nodeFrames[$id] = $frame->moveBy($rowShift, $columnShift);
			}
		}

		$this->frame = GridFrame::merge(
			...array_values($this->nodeFrames),
			...array_map(
				static fn(GridPoint $point) => GridFrame::createFromPoint($point),
				array_values($this->anchors)
			)
		);

		return $this;
	}

	public function findAnchor(string $id): GridPoint
	{
		return $this->anchors[$id] ?? new GridPoint(0, 0);
	}

	public function findNodeFrame(string $id): ?GridFrame
	{
		return $this->nodeFrames[$id] ?? null;
	}

	public function calculateNodeBounds(): GridFrame
	{
		if (empty($this->nodeFrames))
		{
			return $this->frame;
		}

		return GridFrame::merge(...array_values($this->nodeFrames));
	}

	public function calculateNodeBoundsByNames(array $names): GridFrame
	{
		$frames = [];
		foreach ($names as $name)
		{
			if (isset($this->nodeFrames[$name]))
			{
				$frames[] = $this->nodeFrames[$name];
			}
		}

		if (empty($frames))
		{
			return $this->frame;
		}

		return GridFrame::merge(...$frames);
	}
}
