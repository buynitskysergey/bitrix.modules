<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Field;

use Bitrix\Crm\V2\Internal\Service\Field\Requirement\RequirementService;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement;
use Bitrix\Crm\V2\Public\Entity\Field\RequirementException;
use Bitrix\Crm\V2\Public\Entity\Field\RequirementTechnicalException;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * Reads field requirement settings of one entity type on behalf of one user.
 *
 * The read is uncached and does not create categories, stages, or field settings.
 */
final class RequirementProvider
{
	private readonly RequirementService $service;

	private function __construct(
		private readonly EntityType $entityType,
		private readonly int $userId,
	)
	{
		$this->service = new RequirementService();
	}

	public static function forEntityType(EntityType $entityType, int $userId): self
	{
		return new self($entityType, $userId);
	}

	/**
	 * @throws RequirementException
	 * @throws RequirementTechnicalException
	 */
	public function get(string $fieldId, ?int $categoryId = null): Requirement
	{
		return $this->service->read($this->entityType, $this->userId, $fieldId, $categoryId);
	}
}
