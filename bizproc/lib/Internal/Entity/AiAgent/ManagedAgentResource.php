<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\AiAgent;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * Live resource owned by a managed system AI agent instance.
 *
 * The row states that the resource, or its reserved creation, belongs to the instance and is not yet
 * confirmed as absent. Retry bookkeeping belongs to the instance, not to the resource; the data payload
 * only carries versioned technical details needed to repeat the cleanup of this resource type.
 */
class ManagedAgentResource implements EntityInterface
{
	public function __construct(
		private readonly ?int $id,
		private readonly int $instanceId,
		private readonly ManagedAgentResourceType $type,
		private readonly string $resourceId,
		private readonly array $data = [],
		private readonly ?DateTime $createdAt = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function isNew(): bool
	{
		return $this->id === null;
	}

	public function getInstanceId(): int
	{
		return $this->instanceId;
	}

	public function getType(): ManagedAgentResourceType
	{
		return $this->type;
	}

	public function getResourceId(): string
	{
		return $this->resourceId;
	}

	public function getData(): array
	{
		return $this->data;
	}

	public function getCreatedAt(): ?DateTime
	{
		return $this->createdAt;
	}

	public function withId(?int $id): self
	{
		return new self(
			id: $id,
			instanceId: $this->instanceId,
			type: $this->type,
			resourceId: $this->resourceId,
			data: $this->data,
			createdAt: $this->createdAt,
		);
	}

	public function withData(array $data): self
	{
		return new self(
			id: $this->id,
			instanceId: $this->instanceId,
			type: $this->type,
			resourceId: $this->resourceId,
			data: $data,
			createdAt: $this->createdAt,
		);
	}

	public static function mapFromArray(array $props): static
	{
		$type = $props['type'] ?? null;
		if (!$type instanceof ManagedAgentResourceType)
		{
			$type = ManagedAgentResourceType::from((string)$type);
		}

		$createdAt = $props['createdAt'] ?? null;

		return new self(
			id: isset($props['id']) ? (int)$props['id'] : null,
			instanceId: (int)($props['instanceId'] ?? 0),
			type: $type,
			resourceId: (string)($props['resourceId'] ?? ''),
			data: (array)($props['data'] ?? []),
			createdAt: $createdAt instanceof DateTime ? $createdAt : null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'instanceId' => $this->instanceId,
			'type' => $this->type->value,
			'resourceId' => $this->resourceId,
			'data' => $this->data,
			'createdAt' => $this->createdAt,
		];
	}
}
