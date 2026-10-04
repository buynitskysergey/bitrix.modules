<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Trait;

use Bitrix\Crm\V2\Public\Entity\Item\Stage;
use Bitrix\Crm\V2\Public\Entity\Item\StageSemantic;
use Bitrix\Crm\V2\Public\Entity\User\Employee;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * Stage-related fields.
 * categoryId is in HasCategories (separate trait — Contact/Company have categories but no stages).
 */
trait HasStagesTrait
{
	// Writable
	private ?string $stageId = null;
	private ?Date $beginDate = null;
	private ?Date $closeDate = null;
	private ?Stage $stage = null;

	// Read-only (computed by system)
	private ?string $stageSemanticId = null;
	private ?DateTime $movedTime = null;
	private ?int $movedById = null;
	private ?Employee $movedBy = null;
	private ?bool $closed = null;
	private ?StageSemantic $stageSemantic = null;

	// --- Writable ---

	public function getStageId(): ?string
	{
		return $this->stageId;
	}

	public function setStageId(?string $stageId): static
	{
		$this->stageId = $stageId;
		$this->markChanged(self::stageId);

		return $this;
	}

	public function getStage(): ?Stage
	{
		return $this->stage;
	}

	public function getStageSemantic(): ?StageSemantic
	{
		return $this->stageSemantic;
	}

	public function getBeginDate(): ?Date
	{
		return $this->beginDate;
	}

	public function setBeginDate(?Date $beginDate): static
	{
		$this->beginDate = $beginDate;
		$this->markChanged(self::beginDate);

		return $this;
	}

	public function getCloseDate(): ?Date
	{
		return $this->closeDate;
	}

	public function setCloseDate(?Date $closeDate): static
	{
		$this->closeDate = $closeDate;
		$this->markChanged(self::closeDate);

		return $this;
	}

	// --- Read-only (populated via internalSet) ---

	public function getStageSemanticId(): ?string
	{
		return $this->stageSemanticId;
	}

	public function getMovedTime(): ?DateTime
	{
		return $this->movedTime;
	}

	public function getMovedById(): ?int
	{
		return $this->movedById;
	}

	public function getMovedBy(): ?Employee
	{
		return $this->movedBy;
	}

	public function getClosed(): ?bool
	{
		return $this->closed;
	}

	// --- internalSet support ---

	protected function internalSetStageField(string $fieldName, mixed $value): bool
	{
		$success = true;
		match ($fieldName)
		{
			self::stageId => $this->stageId = $value,
			'stage' => $this->stage = $value,
			self::stageSemanticId => $this->stageSemanticId = $value,
			'stageSemantic' => $this->stageSemantic = $value,
			self::movedTime => $this->movedTime = $value,
			self::movedById => $this->movedById = $value,
			self::movedBy => $this->movedBy = $value,
			self::closed => $this->closed = $value,
			self::beginDate => $this->beginDate = $value,
			self::closeDate => $this->closeDate = $value,
			default => $success = false,
		};

		return $success;
	}
}
