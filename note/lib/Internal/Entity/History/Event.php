<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Entity\History;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * A fact-only history record (b_note_event): no DETAILS/payload. VERSION_ID is a
 * structural reference to a version snapshot for content events, not "detail" data.
 */
final class Event implements EntityInterface
{
	public function __construct(
		private readonly ?int $id,
		private readonly string $scope,
		private readonly int $entityId,
		private readonly string $eventType,
		private readonly int $userId,
		private readonly ?int $versionId,
		private readonly DateTime $createdAt,
	) {}

	public static function create(
		string $scope,
		int $entityId,
		string $eventType,
		int $userId,
		?int $versionId = null,
	): self
	{
		return new self(null, $scope, $entityId, $eventType, $userId, $versionId, new DateTime());
	}

	/**
	 * Same as create() but with an explicit CREATED_AT — used to register events with a
	 * historical timestamp (e.g. the lazy 'created' backfill for pre-history documents).
	 */
	public static function createAt(
		string $scope,
		int $entityId,
		string $eventType,
		int $userId,
		?int $versionId,
		DateTime $createdAt,
	): self
	{
		return new self(null, $scope, $entityId, $eventType, $userId, $versionId, $createdAt);
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getScope(): string
	{
		return $this->scope;
	}

	public function getEntityId(): int
	{
		return $this->entityId;
	}

	public function getEventType(): string
	{
		return $this->eventType;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getVersionId(): ?int
	{
		return $this->versionId;
	}

	public function getCreatedAt(): DateTime
	{
		return $this->createdAt;
	}
}
