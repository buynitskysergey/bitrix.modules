<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckPauseWorkflowCommand;

/**
 * Position of the sweep inside its MODIFIED window. The stages are walked one after another, each with
 * a keyset pair of its own: a cursor shared between them would rescan the empty tail of the other
 * stage on every page. The pair survives the removal of its row - unlike a plain id, whose position
 * paging would have to resolve by the instance it just deleted.
 */
final class StuckPauseSweepCursor implements \Stringable
{
	public const STAGE_SUSPENDED = 'suspended';
	public const STAGE_STALE_LOCKED = 'staleLocked';

	public function __construct(
		public readonly string $stage,
		public readonly ?int $afterModifiedTimestamp = null,
		public readonly ?string $afterWorkflowId = null,
	)
	{
	}

	public static function start(): self
	{
		return new self(self::STAGE_SUSPENDED);
	}

	public function withPosition(int $modifiedTimestamp, string $workflowId): self
	{
		return new self($this->stage, $modifiedTimestamp, $workflowId);
	}

	public function nextStage(): ?self
	{
		return $this->stage === self::STAGE_SUSPENDED ? new self(self::STAGE_STALE_LOCKED) : null;
	}

	// A workflow id is hex of uniqid() with a dot, so the separator never occurs inside one.
	public function __toString(): string
	{
		return implode(':', [$this->stage, $this->afterModifiedTimestamp ?? '', $this->afterWorkflowId ?? '']);
	}

	/**
	 * An unreadable value restarts the chain from its beginning instead of failing the agent for good.
	 */
	public static function tryParse(?string $value): ?self
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		$parts = explode(':', $value, 3);
		if (count($parts) !== 3 || !in_array($parts[0], [self::STAGE_SUSPENDED, self::STAGE_STALE_LOCKED], true))
		{
			return null;
		}

		[$stage, $modifiedTimestamp, $workflowId] = $parts;
		if ($modifiedTimestamp === '' && $workflowId === '')
		{
			return new self($stage);
		}

		if (preg_match('/^[0-9]+$/D', $modifiedTimestamp) !== 1 || $workflowId === '')
		{
			return null;
		}

		return new self($stage, (int)$modifiedTimestamp, $workflowId);
	}
}
