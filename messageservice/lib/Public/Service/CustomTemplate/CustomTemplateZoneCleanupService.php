<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Service\CustomTemplate;

use Bitrix\MessageService\Internal\Repository\CustomTemplateRepository;
use Bitrix\MessageService\Internal\ValueObject\CustomTemplateBinding as InternalTemplateBinding;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;

final readonly class CustomTemplateZoneCleanupService
{
	public function __construct(
		private CustomTemplateRepository $repository,
	)
	{
	}

	public function cleanupZone(string $zone): void
	{
		$this->repository->deleteAllInZone($zone);
	}

	public function cleanupByBinding(TemplateBinding $binding): void
	{
		$this->repository->deleteAllByBinding(
			new InternalTemplateBinding($binding->zone, $binding->scene, $binding->targetId),
		);
	}

	public function cleanupByTarget(string $zone, string $targetId): void
	{
		$this->repository->deleteAllByZoneAndTarget($zone, $targetId);
	}

	public function cleanupByTargetPrefix(string $zone, string $targetPrefix): void
	{
		$this->repository->deleteAllByZoneAndTargetPrefix($zone, $targetPrefix);
	}
}
