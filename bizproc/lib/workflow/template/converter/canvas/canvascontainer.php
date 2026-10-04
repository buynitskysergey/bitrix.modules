<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasContainer
{
	private string $direction = CanvasDirection::VERTICAL;
	private float $rowGap = 1;
	private float $columnGap = 1;
	private float $offsetRow = 0;
	private float $offsetColumn = 0;
	private CanvasPadding $padding;
	private array $items = [];
	private ?CanvasFrame $frame = null;

	public function __construct(
		private readonly string $id = '',
	) {
		$this->padding = CanvasPadding::createUniform(0);
	}

	public static function create(string $id = ''): self
	{
		return new self($id);
	}

	public function arrangeVertically(): self
	{
		$this->direction = CanvasDirection::VERTICAL;

		return $this;
	}

	public function arrangeHorizontally(): self
	{
		$this->direction = CanvasDirection::HORIZONTAL;

		return $this;
	}

	public function defineGap(float $gap): self
	{
		$this->rowGap = $gap;
		$this->columnGap = $gap;

		return $this;
	}

	public function defineRowGap(float $gap): self
	{
		$this->rowGap = $gap;

		return $this;
	}

	public function defineColumnGap(float $gap): self
	{
		$this->columnGap = $gap;

		return $this;
	}

	public function definePadding(CanvasPadding $padding): self
	{
		$this->padding = $padding;

		return $this;
	}

	public function defineOffset(float $row, float $column): self
	{
		$this->offsetRow = $row;
		$this->offsetColumn = $column;

		return $this;
	}

	public function defineRowOffset(float $row): self
	{
		$this->offsetRow = $row;

		return $this;
	}

	public function defineColumnOffset(float $column): self
	{
		$this->offsetColumn = $column;

		return $this;
	}

	public function defineUniformPadding(float $padding): self
	{
		$this->padding = CanvasPadding::createUniform($padding);

		return $this;
	}

	public function defineFrame(string $frameId, ?string $title = null): self
	{
		$this->frame = CanvasFrame::create($frameId, $title);

		return $this;
	}

	public function appendNode(CanvasNode $node): self
	{
		$this->items[] = $node;

		return $this;
	}

	public function appendActivity(array $activity): self
	{
		return $this->appendNode(CanvasNode::createFromActivity($activity));
	}

	public function appendNodes(array $nodes): self
	{
		foreach ($nodes as $node)
		{
			if ($node instanceof CanvasNode)
			{
				$this->appendNode($node);
			}
		}

		return $this;
	}

	public function appendContainer(self $container): self
	{
		$this->items[] = $container;

		return $this;
	}

	public function appendFragment(CanvasFragment $fragment): self
	{
		$container = self::create()->importFragment($fragment);
		if ($container->findItems())
		{
			$this->items[] = $container;
		}

		return $this;
	}

	public function importFragment(CanvasFragment $fragment): self
	{
		if ($fragment->findRootContainer())
		{
			return $this->appendContainer($fragment->findRootContainer());
		}

		foreach ($fragment->findNodes() as $node)
		{
			$this->appendNode($node);
		}

		return $this;
	}

	public function findId(): string
	{
		return $this->id;
	}

	public function findDirection(): string
	{
		return $this->direction;
	}

	public function findRowGap(): float
	{
		return $this->rowGap;
	}

	public function findColumnGap(): float
	{
		return $this->columnGap;
	}

	public function findPadding(): CanvasPadding
	{
		return $this->padding;
	}

	public function findOffsetRow(): float
	{
		return $this->offsetRow;
	}

	public function findOffsetColumn(): float
	{
		return $this->offsetColumn;
	}

	public function findItems(): array
	{
		return $this->items;
	}

	public function findFrame(): ?CanvasFrame
	{
		if (!$this->frame)
		{
			return null;
		}

		return $this->frame->wrapNodes($this->findNodeIdsRecursively());
	}

	public function findNodeIdsRecursively(): array
	{
		$nodeIds = [];

		foreach ($this->items as $item)
		{
			if ($item instanceof CanvasNode)
			{
				$nodeIds[] = $item->findId();
			}
			elseif ($item instanceof self)
			{
				array_push($nodeIds, ...$item->findNodeIdsRecursively());
			}
		}

		return array_values(array_unique($nodeIds));
	}
}
