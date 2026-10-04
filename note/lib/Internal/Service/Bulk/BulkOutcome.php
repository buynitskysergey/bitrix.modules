<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Bulk;

/**
 * [FEAT-kb2-tree-bulk-archive / P1.T3 / DTO-01] Immutable aggregate report of a bulk
 * operation. Owner of the DTO-01 wire contract: the frontend reads only toArray().
 *
 * Aggregates only — no per-object detail. Access failures, recycle orphans and transient
 * failures are each carried as a single running count. limitExceeded is terminal: when set,
 * nothing was applied and processedCount is forced to zero.
 */
final readonly class BulkOutcome
{
	public function __construct(
		public int $processedCount = 0,
		public int $skippedByAccessCount = 0,
		public int $skippedOrphanCount = 0,
		public int $skippedTransientCount = 0,
		public bool $limitExceeded = false,
	) {}

	/**
	 * Terminal outcome: the resolved selection exceeded the cap, so no action was applied.
	 */
	public static function limitExceeded(): self
	{
		return new self(limitExceeded: true);
	}

	/**
	 * Starting point for a run whose selection passed the cap, seeded with the access
	 * verdict (documents skipped for lack of rights). Chunk results are folded in via
	 * withChunk().
	 */
	public static function fromAccess(int $skippedByAccessCount): self
	{
		return new self(skippedByAccessCount: max(0, $skippedByAccessCount));
	}

	/**
	 * Folds one applied chunk's counts into a new outcome (immutable accumulation).
	 */
	public function withChunk(int $processed, int $skippedOrphan = 0, int $skippedTransient = 0): self
	{
		return new self(
			$this->processedCount + max(0, $processed),
			$this->skippedByAccessCount,
			$this->skippedOrphanCount + max(0, $skippedOrphan),
			$this->skippedTransientCount + max(0, $skippedTransient),
			$this->limitExceeded,
		);
	}

	/**
	 * Total skipped across all reasons (access + orphan + transient).
	 */
	public function skippedCount(): int
	{
		return $this->skippedByAccessCount + $this->skippedOrphanCount + $this->skippedTransientCount;
	}

	/**
	 * DTO-01 wire form. Keys and types are the source of truth for the frontend.
	 *
	 * @return array{
	 *     processedCount: int,
	 *     skippedCount: int,
	 *     skippedByAccessCount: int,
	 *     skippedOrphanCount: int,
	 *     limitExceeded: bool
	 * }
	 */
	public function toArray(): array
	{
		if ($this->limitExceeded)
		{
			return [
				'processedCount' => 0,
				'skippedCount' => 0,
				'skippedByAccessCount' => 0,
				'skippedOrphanCount' => 0,
				'limitExceeded' => true,
			];
		}

		return [
			'processedCount' => $this->processedCount,
			'skippedCount' => $this->skippedCount(),
			'skippedByAccessCount' => $this->skippedByAccessCount,
			'skippedOrphanCount' => $this->skippedOrphanCount,
			'limitExceeded' => false,
		];
	}
}
