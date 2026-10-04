<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Public\EntityType;

/**
 * @internal
 */
class Context
{
	public function __construct(
		private readonly int $userId,
		private readonly mixed $value,
		private readonly bool $showValues = false,
		private readonly array $extraArgs = [],
	)
	{
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function isShowValues(): bool
	{
		return $this->showValues;
	}

	public function getEntityType(): EntityType
	{
		return EntityType::fromId($this->getExtraArgs()['entityTypeId'] ?? 0);
	}

	public function getExtraArgs(): array
	{
		return $this->extraArgs;
	}
}
