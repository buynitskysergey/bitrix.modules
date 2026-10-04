<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasFrame
{
	private array $nodeIds = [];

	public function __construct(
		private readonly string $id,
		private readonly ?string $title = null,
	) {}

	public static function create(string $id, ?string $title = null): self
	{
		return new self($id, $title);
	}

	public function wrapFragment(CanvasFragment $fragment): self
	{
		return $this->wrapNodes($fragment->findNodeIds());
	}

	public function wrapNodes(array $nodeIds): self
	{
		$this->nodeIds = array_values(array_unique([...$this->nodeIds, ...$nodeIds]));

		return $this;
	}

	public function findId(): string
	{
		return $this->id;
	}

	public function findTitle(): ?string
	{
		return $this->title;
	}

	public function findWrappedNodeIds(): array
	{
		return $this->nodeIds;
	}
}
