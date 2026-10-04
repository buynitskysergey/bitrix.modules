<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

final class ColumnState
{
	private bool $resolved = false;
	private ?ColumnStateData $data = null;
	private ?\Closure $resolver;

	/**
	 * @param \Closure(): ?ColumnStateData $resolver
	 */
	public function __construct(
		private readonly string $name,
		\Closure $resolver,
	)
	{
		$this->resolver = $resolver;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function exists(): bool
	{
		return $this->resolve() !== null;
	}

	public function getType(): string
	{
		return $this->requireData()->getType();
	}

	public function getAutoincrementType(): ColumnAutoincrementType
	{
		return $this->requireData()->getAutoincrementType();
	}

	public function getSequenceSchema(): ?string
	{
		return $this->requireData()->getSequenceSchema();
	}

	public function getSequenceName(): ?string
	{
		return $this->requireData()->getSequenceName();
	}

	public function getSequenceType(): ?string
	{
		return $this->requireData()->getSequenceType();
	}

	private function resolve(): ?ColumnStateData
	{
		if (!$this->resolved)
		{
			$this->data = ($this->resolver)();
			$this->resolver = null;
			$this->resolved = true;
		}

		return $this->data;
	}

	private function requireData(): ColumnStateData
	{
		return $this->resolve() ?? throw new \LogicException(
			'Column state data is unavailable for missing column "' . $this->name . '"',
		);
	}
}
