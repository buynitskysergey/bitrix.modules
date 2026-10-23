<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Attribute\Entity\FieldAttributeTable;
use Bitrix\Crm\Attribute\FieldAttributeManager;
use Bitrix\Crm\Attribute\FieldAttributePhaseGroupType;
use Bitrix\Crm\Attribute\FieldAttributeType;
use Bitrix\Crm\V2\Public\Entity\Field\RequirementError;
use Bitrix\Crm\V2\Public\Entity\Field\RequirementException;
use Bitrix\Crm\V2\Public\EntityType;

final class LegacyRequirementAdapter
{
	public function read(
		EntityType $entityType,
		?int $categoryId,
		string $legacyFieldName,
	): LegacyRequirementRule
	{
		$rows = FieldAttributeTable::query()
			->setSelect([
				'ID',
				'START_PHASE',
				'FINISH_PHASE',
				'PHASE_GROUP_TYPE_ID',
			])
			->where('ENTITY_TYPE_ID', $entityType->getId())
			->where('ENTITY_SCOPE', FieldAttributeManager::getEntityScopeByCategory($categoryId))
			->where('TYPE_ID', FieldAttributeType::REQUIRED)
			->where('FIELD_NAME', $legacyFieldName)
			->where('IS_CUSTOM_FIELD', str_starts_with($legacyFieldName, 'UF_') ? 'Y' : 'N')
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		if ($rows === [])
		{
			return LegacyRequirementRule::none();
		}

		$hasAll = false;
		$ranges = [];
		foreach ($rows as $row)
		{
			$phaseGroupTypeId = (int)$row['PHASE_GROUP_TYPE_ID'];
			$startPhaseId = $row['START_PHASE'];
			$finishPhaseId = $row['FINISH_PHASE'];

			if (!FieldAttributePhaseGroupType::isDefined($phaseGroupTypeId))
			{
				throw $this->configurationConflict();
			}

			if ($phaseGroupTypeId === FieldAttributePhaseGroupType::ALL)
			{
				if ($startPhaseId !== '' || $finishPhaseId !== '')
				{
					throw $this->configurationConflict();
				}

				$hasAll = true;

				continue;
			}

			if ($startPhaseId === '' || $finishPhaseId === '')
			{
				throw $this->configurationConflict();
			}

			$ranges[] = new LegacyRequirementRange(
				$phaseGroupTypeId,
				$startPhaseId,
				$finishPhaseId,
			);
		}

		if ($hasAll && $ranges !== [])
		{
			throw $this->configurationConflict();
		}

		return $hasAll ? LegacyRequirementRule::all() : LegacyRequirementRule::ranges($ranges);
	}

	private function configurationConflict(): RequirementException
	{
		return new RequirementException(RequirementError::CONFIGURATION_CONFLICT);
	}
}
