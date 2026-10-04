<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\AiAgent;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * Managed instance of a system AI agent: the durable link between an external context and a launched copy.
 *
 * The identity hash is the uniqueness source of truth, while the context components are kept alongside it
 * so that a hash match can be confirmed against the stored namespace, type and id.
 */
class ManagedAgentInstance implements EntityInterface
{
	public function __construct(
		private readonly ?int $id,
		private readonly string $identityHash,
		private readonly string $systemCode,
		private readonly string $contextNamespace,
		private readonly string $contextType,
		private readonly string $contextId,
		private readonly int $userId,
		private readonly ?int $templateId,
		private readonly ManagedAgentInstanceState $state,
		private readonly string $configFingerprint,
		private readonly int $retryCount = 0,
		private readonly ?DateTime $nextRetryAt = null,
		private readonly ?string $lastErrorCode = null,
		private readonly ?DateTime $createdAt = null,
		private readonly ?DateTime $updatedAt = null,
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

	public function getIdentityHash(): string
	{
		return $this->identityHash;
	}

	public function getSystemCode(): string
	{
		return $this->systemCode;
	}

	public function getContextNamespace(): string
	{
		return $this->contextNamespace;
	}

	public function getContextType(): string
	{
		return $this->contextType;
	}

	public function getContextId(): string
	{
		return $this->contextId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getTemplateId(): ?int
	{
		return $this->templateId;
	}

	public function getState(): ManagedAgentInstanceState
	{
		return $this->state;
	}

	public function getConfigFingerprint(): string
	{
		return $this->configFingerprint;
	}

	public function getRetryCount(): int
	{
		return $this->retryCount;
	}

	public function getNextRetryAt(): ?DateTime
	{
		return $this->nextRetryAt;
	}

	public function getLastErrorCode(): ?string
	{
		return $this->lastErrorCode;
	}

	public function getCreatedAt(): ?DateTime
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): ?DateTime
	{
		return $this->updatedAt;
	}

	public function withId(?int $id): self
	{
		return new self(
			id: $id,
			identityHash: $this->identityHash,
			systemCode: $this->systemCode,
			contextNamespace: $this->contextNamespace,
			contextType: $this->contextType,
			contextId: $this->contextId,
			userId: $this->userId,
			templateId: $this->templateId,
			state: $this->state,
			configFingerprint: $this->configFingerprint,
			retryCount: $this->retryCount,
			nextRetryAt: $this->nextRetryAt,
			lastErrorCode: $this->lastErrorCode,
			createdAt: $this->createdAt,
			updatedAt: $this->updatedAt,
		);
	}

	public function withTemplateId(?int $templateId, DateTime $updatedAt): self
	{
		return new self(
			id: $this->id,
			identityHash: $this->identityHash,
			systemCode: $this->systemCode,
			contextNamespace: $this->contextNamespace,
			contextType: $this->contextType,
			contextId: $this->contextId,
			userId: $this->userId,
			templateId: $templateId,
			state: $this->state,
			configFingerprint: $this->configFingerprint,
			retryCount: $this->retryCount,
			nextRetryAt: $this->nextRetryAt,
			lastErrorCode: $this->lastErrorCode,
			createdAt: $this->createdAt,
			updatedAt: $updatedAt,
		);
	}

	public function withState(ManagedAgentInstanceState $state, DateTime $updatedAt): self
	{
		return new self(
			id: $this->id,
			identityHash: $this->identityHash,
			systemCode: $this->systemCode,
			contextNamespace: $this->contextNamespace,
			contextType: $this->contextType,
			contextId: $this->contextId,
			userId: $this->userId,
			templateId: $this->templateId,
			state: $state,
			configFingerprint: $this->configFingerprint,
			retryCount: $this->retryCount,
			nextRetryAt: $this->nextRetryAt,
			lastErrorCode: $this->lastErrorCode,
			createdAt: $this->createdAt,
			updatedAt: $updatedAt,
		);
	}

	public function withRetry(
		int $retryCount,
		?DateTime $nextRetryAt,
		?string $lastErrorCode,
		DateTime $updatedAt
	): self
	{
		return new self(
			id: $this->id,
			identityHash: $this->identityHash,
			systemCode: $this->systemCode,
			contextNamespace: $this->contextNamespace,
			contextType: $this->contextType,
			contextId: $this->contextId,
			userId: $this->userId,
			templateId: $this->templateId,
			state: $this->state,
			configFingerprint: $this->configFingerprint,
			retryCount: $retryCount,
			nextRetryAt: $nextRetryAt,
			lastErrorCode: $lastErrorCode,
			createdAt: $this->createdAt,
			updatedAt: $updatedAt,
		);
	}

	public static function mapFromArray(array $props): static
	{
		$state = $props['state'] ?? null;
		if (!$state instanceof ManagedAgentInstanceState)
		{
			$state = ManagedAgentInstanceState::from((string)$state);
		}

		return new self(
			id: isset($props['id']) ? (int)$props['id'] : null,
			identityHash: (string)($props['identityHash'] ?? ''),
			systemCode: (string)($props['systemCode'] ?? ''),
			contextNamespace: (string)($props['contextNamespace'] ?? ''),
			contextType: (string)($props['contextType'] ?? ''),
			contextId: (string)($props['contextId'] ?? ''),
			userId: (int)($props['userId'] ?? 0),
			templateId: isset($props['templateId']) ? (int)$props['templateId'] : null,
			state: $state,
			configFingerprint: (string)($props['configFingerprint'] ?? ''),
			retryCount: (int)($props['retryCount'] ?? 0),
			nextRetryAt: self::mapDateTime($props['nextRetryAt'] ?? null),
			lastErrorCode: isset($props['lastErrorCode']) ? (string)$props['lastErrorCode'] : null,
			createdAt: self::mapDateTime($props['createdAt'] ?? null),
			updatedAt: self::mapDateTime($props['updatedAt'] ?? null),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'identityHash' => $this->identityHash,
			'systemCode' => $this->systemCode,
			'contextNamespace' => $this->contextNamespace,
			'contextType' => $this->contextType,
			'contextId' => $this->contextId,
			'userId' => $this->userId,
			'templateId' => $this->templateId,
			'state' => $this->state->value,
			'configFingerprint' => $this->configFingerprint,
			'retryCount' => $this->retryCount,
			'nextRetryAt' => $this->nextRetryAt,
			'lastErrorCode' => $this->lastErrorCode,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}

	private static function mapDateTime(mixed $value): ?DateTime
	{
		return $value instanceof DateTime ? $value : null;
	}
}
