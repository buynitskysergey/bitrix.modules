<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Dto;

final class CombineResult
{
	/** @var CombineRow[] */
	private readonly array $rows;

	/** @param CombineRow[] $rows */
	public function __construct(array $rows)
	{
		$this->rows = array_values($rows);
	}

	/** @return CombineRow[] */
	public function getRows(): array
	{
		return $this->rows;
	}

	public function count(): int
	{
		return count($this->rows);
	}

	public function isEmpty(): bool
	{
		return $this->rows === [];
	}
}
