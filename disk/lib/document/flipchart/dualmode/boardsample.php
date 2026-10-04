<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

/**
 * The answer of PilotStorageInspector: whether the storage holds a board, plus a bounded number of
 * examples for the operator.
 */
final class BoardSample
{
	/**
	 * @param int[] $ids
	 */
	public function __construct(
		private readonly array $ids,
		private readonly bool $hasMore,
	)
	{
	}

	public function isEmpty(): bool
	{
		return $this->ids === [];
	}

	/**
	 * @return int[]
	 */
	public function getIds(): array
	{
		return $this->ids;
	}

	public function hasMore(): bool
	{
		return $this->hasMore;
	}

	public function describe(): string
	{
		return implode(', ', $this->ids) . ($this->hasMore ? ' and more' : '');
	}
}
