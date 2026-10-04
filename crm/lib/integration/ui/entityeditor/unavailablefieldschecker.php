<?php

namespace Bitrix\Crm\Integration\UI\EntityEditor;

use Bitrix\Crm\Attribute\FieldAttributeManager;
use Bitrix\Crm\Attribute\FieldOrigin;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\Helper\Stage;
use CCrmOwnerType;

final readonly class UnavailableFieldsChecker
{
	private const TARGET_TYPES = [
		'moneyPay',
		'client_light',
		'crm',
		'crm_entity_tag',
	];

	private const TARGET_CLIENT_TYPES = [
		'moneyPay',
		'client_light',
	];

	public function __construct(
		private array $availableFields,
		private array $requiredFields,
		private int $entityTypeId,
		private ?int $categoryId = null,
	)
	{
	}

	public function getUnavailableRequiredFields(): array
	{
		if (Container::getInstance()->getUserPermissions()->isAdmin())
		{
			return [];
		}

		[$fieldToEntityTypeIds, $uniqueEntityTypeIds] = $this->collectEntityTypeIdsFromAvailableFields();
		$inaccessibleEntityTypeIds = $this->getInaccessibleEntityTypeIds($uniqueEntityTypeIds);
		$inaccessibleFieldNames = $this->filterFieldsFullyInaccessible($fieldToEntityTypeIds, $inaccessibleEntityTypeIds);
		$inaccessibleRequiredFieldNames = array_intersect($inaccessibleFieldNames, array_keys($this->requiredFields));
		$inaccessibleByStagesFieldNames = array_diff($inaccessibleFieldNames, array_keys($this->requiredFields));

		$allStagesFields = $this->buildFieldTitlesMap($inaccessibleRequiredFieldNames);
		$inaccessibleByStages = empty($allStagesFields) ? [] : ['ALL' => $allStagesFields];

		if (count($inaccessibleRequiredFieldNames) === count($inaccessibleFieldNames))
		{
			return $inaccessibleByStages;
		}

		$stages = Container::getInstance()->getStageBroker($this->entityTypeId)?->getByCategoryId($this->categoryId);
		$stageFieldName = Stage::getStageFieldName($this->entityTypeId);

		if ($stages === null || empty($stageFieldName))
		{
			return $inaccessibleByStages;
		}

		foreach ($stages as $stage)
		{
			$stageId = $stage['STATUS_ID'];
			$stageRequiredFields = FieldAttributeManager::getRequiredFields(
				$this->entityTypeId,
				0,
				[
					$stageFieldName => $stageId,
				],
			);

			$stageRequiredFieldsList = array_unique([
				...($stageRequiredFields[FieldOrigin::SYSTEM] ?? []),
				...($stageRequiredFields[FieldOrigin::CUSTOM] ?? []),
			]);

			$inaccessibleFieldNamesByStage = array_intersect($inaccessibleByStagesFieldNames, $stageRequiredFieldsList);

			if (!empty($inaccessibleFieldNamesByStage))
			{
				$inaccessibleByStages[$stageId] = $this->buildFieldTitlesMap($inaccessibleFieldNamesByStage);
			}
		}

		return $inaccessibleByStages;
	}

	private function collectEntityTypeIdsFromAvailableFields(): array
	{
		$fieldToEntityTypeIds = [];
		$uniqueEntityTypeIds = [];

		foreach ($this->availableFields as $fieldName => $fieldInfo)
		{
			$type = $fieldInfo['type'] ?? null;

			if (in_array($type, self::TARGET_TYPES, true))
			{
				$entityTypeIds = [];

				if (in_array($type, self::TARGET_CLIENT_TYPES, true))
				{
					$compound = $fieldInfo['data']['compound'] ?? [];
					foreach ($compound as $compoundItem)
					{
						$entityTypeName = $compoundItem['entityTypeName'] ?? null;
						if ($entityTypeName === null)
						{
							continue;
						}

						$entityTypeId = CCrmOwnerType::ResolveID($entityTypeName);
						$entityTypeIds[] = $entityTypeId;
						$uniqueEntityTypeIds[$entityTypeId] = $entityTypeId;
					}
				}
				elseif ($type === 'crm')
				{
					$fieldEntityTypeIds = $fieldInfo['data']['entityTypeIds'] ?? [];
					foreach ($fieldEntityTypeIds as $entityTypeId)
					{
						$entityTypeId = (int)$entityTypeId;
						$entityTypeIds[] = $entityTypeId;
						$uniqueEntityTypeIds[$entityTypeId] = $entityTypeId;
					}
				}
				elseif ($type === 'crm_entity_tag')
				{
					$entityTypeId = (int)($fieldInfo['data']['typeId'] ?? 0);
					if ($entityTypeId > 0)
					{
						$entityTypeIds[] = $entityTypeId;
						$uniqueEntityTypeIds[$entityTypeId] = $entityTypeId;
					}
				}

				if (!empty($entityTypeIds))
				{
					$fieldToEntityTypeIds[$fieldName] = $entityTypeIds;
				}
			}
			elseif ($type === 'userField')
			{
				$userFieldType = $fieldInfo['data']['fieldInfo']['USER_TYPE_ID'] ?? null;
				if ($userFieldType === 'crm')
				{
					$settings = $fieldInfo['data']['fieldInfo']['SETTINGS'] ?? [];
					$entityTypeIds = [];
					foreach ($settings as $entityTypeName => $value)
					{
						if ($value === 'Y')
						{
							$entityTypeId = CCrmOwnerType::ResolveID($entityTypeName);
							$entityTypeIds[] = $entityTypeId;
							$uniqueEntityTypeIds[$entityTypeId] = $entityTypeId;
						}
					}

					if (!empty($entityTypeIds))
					{
						$fieldToEntityTypeIds[$fieldName] = $entityTypeIds;
					}
				}
			}
		}

		return [$fieldToEntityTypeIds, array_values($uniqueEntityTypeIds)];
	}

	private function getInaccessibleEntityTypeIds(array $entityTypeIds): array
	{
		$inaccessible = [];

		if (empty($entityTypeIds))
		{
			return $inaccessible;
		}

		$userPermissions = Container::getInstance()->getUserPermissions();
		foreach ($entityTypeIds as $entityTypeId)
		{
			$canRead = $userPermissions->entityType()->canReadItems($entityTypeId);
			if (!$canRead)
			{
				$inaccessible[] = $entityTypeId;
			}
		}

		return $inaccessible;
	}

	private function filterFieldsFullyInaccessible(array $fieldToEntityTypeIds, array $inaccessibleEntityTypeIds): array
	{
		$inaccessibleFieldNames = array_filter(
			$fieldToEntityTypeIds,
			static fn($values) => count(array_diff($values, $inaccessibleEntityTypeIds)) === 0,
		);

		return array_keys($inaccessibleFieldNames);
	}

	private function buildFieldTitlesMap(array $fieldNames): array
	{
		$result = [];
		foreach ($fieldNames as $name)
		{
			$result[$name] = $this->availableFields[$name]['title'] ?? $name;
		}

		return $result;
	}
}
