<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\DataView;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;
use Bitrix\Bizproc\Internal\Exception\DataView\InvalidDataViewDefinitionException;

class DataView implements EntityInterface
{
	private readonly ?int $id;
	private readonly int $storageTypeId;
	private readonly array $definition;
	private readonly DataViewStatus $status;
	private readonly ?string $errorText;
	private readonly array $deletionMarks;
	private readonly ?int $materializedAt;
	private readonly ?int $materializedBy;
	private readonly ?int $ownerTemplateId;
	private readonly ?string $ownerActivityName;
	private readonly ?int $createdBy;
	private readonly ?int $updatedBy;
	private readonly ?int $createdAt;
	private readonly ?int $updatedAt;

	public function __construct(
		?int $id,
		int $storageTypeId,
		array $definition,
		DataViewStatus $status = DataViewStatus::NotMaterialized,
		?string $errorText = null,
		array $deletionMarks = [],
		?int $materializedAt = null,
		?int $materializedBy = null,
		?int $ownerTemplateId = null,
		?string $ownerActivityName = null,
		?int $createdBy = null,
		?int $updatedBy = null,
		?int $createdAt = null,
		?int $updatedAt = null,
	)
	{
		self::assertValidDefinition($definition);

		$this->id = $id;
		$this->storageTypeId = $storageTypeId;
		$this->definition = $definition;
		$this->status = $status;
		$this->errorText = $errorText;
		$this->deletionMarks = array_values($deletionMarks);
		$this->materializedAt = $materializedAt;
		$this->materializedBy = $materializedBy;
		$this->ownerTemplateId = $ownerTemplateId;
		$this->ownerActivityName = $ownerActivityName;
		$this->createdBy = $createdBy;
		$this->updatedBy = $updatedBy;
		$this->createdAt = $createdAt;
		$this->updatedAt = $updatedAt;
	}

	private static function assertValidDefinition(array $definition): void
	{
		$operation = $definition['operation'] ?? null;
		$expectedSourceCount = match ($operation)
		{
			'join' => 2,
			'aggregate', 'project' => 1,
			default => throw new InvalidDataViewDefinitionException(
				'Data view operation must be "join", "aggregate" or "project".'
			),
		};

		$sources = $definition['sources'] ?? null;
		if (!is_array($sources) || count($sources) !== $expectedSourceCount)
		{
			throw new InvalidDataViewDefinitionException(sprintf(
				'Data view with operation "%s" must have exactly %d source(s).',
				$operation,
				$expectedSourceCount,
			));
		}

		$period = $definition['period'] ?? null;
		if (!is_array($period) || $period === [])
		{
			throw new InvalidDataViewDefinitionException('Data view period is required.');
		}
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function isNew(): bool
	{
		return $this->id === null;
	}

	public function getStorageTypeId(): int
	{
		return $this->storageTypeId;
	}

	public function getDefinition(): array
	{
		return $this->definition;
	}

	public function getStatus(): DataViewStatus
	{
		return $this->status;
	}

	public function getErrorText(): ?string
	{
		return $this->errorText;
	}

	public function getDeletionMarks(): array
	{
		return $this->deletionMarks;
	}

	public function getMaterializedAt(): ?int
	{
		return $this->materializedAt;
	}

	public function getMaterializedBy(): ?int
	{
		return $this->materializedBy;
	}

	public function getOwnerTemplateId(): ?int
	{
		return $this->ownerTemplateId;
	}

	public function getOwnerActivityName(): ?string
	{
		return $this->ownerActivityName;
	}

	public function getCreatedBy(): ?int
	{
		return $this->createdBy;
	}

	public function getUpdatedBy(): ?int
	{
		return $this->updatedBy;
	}

	public function getCreatedAt(): ?int
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): ?int
	{
		return $this->updatedAt;
	}

	public function withId(?int $id): self
	{
		return new self(
			id: $id,
			storageTypeId: $this->storageTypeId,
			definition: $this->definition,
			status: $this->status,
			errorText: $this->errorText,
			deletionMarks: $this->deletionMarks,
			materializedAt: $this->materializedAt,
			materializedBy: $this->materializedBy,
			ownerTemplateId: $this->ownerTemplateId,
			ownerActivityName: $this->ownerActivityName,
			createdBy: $this->createdBy,
			updatedBy: $this->updatedBy,
			createdAt: $this->createdAt,
			updatedAt: $this->updatedAt,
		);
	}

	public static function mapFromArray(array $props): static
	{
		$status = $props['status'] ?? null;
		if (!$status instanceof DataViewStatus)
		{
			$status = DataViewStatus::fromString($status !== null ? (string)$status : null);
		}

		return new self(
			id: isset($props['id']) ? (int)$props['id'] : null,
			storageTypeId: (int)($props['storageTypeId'] ?? 0),
			definition: (array)($props['definition'] ?? []),
			status: $status,
			errorText: isset($props['errorText']) ? (string)$props['errorText'] : null,
			deletionMarks: (array)($props['deletionMarks'] ?? []),
			materializedAt: isset($props['materializedAt']) ? (int)$props['materializedAt'] : null,
			materializedBy: isset($props['materializedBy']) ? (int)$props['materializedBy'] : null,
			ownerTemplateId: isset($props['ownerTemplateId']) ? (int)$props['ownerTemplateId'] : null,
			ownerActivityName: isset($props['ownerActivityName']) ? (string)$props['ownerActivityName'] : null,
			createdBy: isset($props['createdBy']) ? (int)$props['createdBy'] : null,
			updatedBy: isset($props['updatedBy']) ? (int)$props['updatedBy'] : null,
			createdAt: isset($props['createdAt']) ? (int)$props['createdAt'] : null,
			updatedAt: isset($props['updatedAt']) ? (int)$props['updatedAt'] : null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'storageTypeId' => $this->storageTypeId,
			'definition' => $this->definition,
			'status' => $this->status->value,
			'errorText' => $this->errorText,
			'deletionMarks' => $this->deletionMarks,
			'materializedAt' => $this->materializedAt,
			'materializedBy' => $this->materializedBy,
			'ownerTemplateId' => $this->ownerTemplateId,
			'ownerActivityName' => $this->ownerActivityName,
			'createdBy' => $this->createdBy,
			'updatedBy' => $this->updatedBy,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
