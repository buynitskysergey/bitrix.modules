<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\Category\Entity\Category as CrmCategory;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Public\Entity\Item\Category;
use Bitrix\Crm\V2\Public\Entity\Item\Currency;
use Bitrix\Crm\V2\Public\Entity\Item\DealType;
use Bitrix\Crm\V2\Public\Entity\Item\Deal;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\Source;
use Bitrix\Crm\V2\Public\Entity\Item\Stage;
use Bitrix\Crm\V2\Public\Entity\Item\StageSemantic;
use Bitrix\Crm\V2\Public\Entity\Item\Webform;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Crm\WebForm\Manager;

class DictionaryLoader
{
	private ?array $stageMapByCategory = null;
	private ?array $stageMapByEntityType = null;
	private ?array $leadStageMap = null;
	private ?array $stageSemanticMap = null;
	private ?array $sourceMap = null;
	private ?array $categoryMap = null;
	private ?array $currencyMap = null;
	private ?array $webformMap = null;
	private ?array $dealTypeMap = null;

	public function __construct(
		protected readonly ?int $accessUserId = null,
	)
	{
	}

	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadDictionaries())
		{
			return;
		}

		$persisted = array_values(array_filter($items, static fn(Item $item): bool => $item->getId() !== null));
		if ($persisted === [])
		{
			return;
		}

		$dictionaryFields = array_keys($select->getDictionariesSelect());
		if (in_array('stage', $dictionaryFields, true) || in_array('previousStage', $dictionaryFields, true))
		{
			$this->primeStageMap($persisted);
			if (array_filter($persisted, static fn(Item $item): bool => $item->getEntityType()->getId() === OwnerType::LEAD) !== [])
			{
				$this->primeLeadStageMap();
			}
		}
		if (in_array('stageSemantic', $dictionaryFields, true))
		{
			$this->primeStageSemanticMap($persisted);
		}
		if (in_array('source', $dictionaryFields, true))
		{
			$this->primeSourceMap($persisted);
		}
		if (in_array('category', $dictionaryFields, true))
		{
			$this->primeCategoryMap($persisted);
		}
		if (in_array('currency', $dictionaryFields, true))
		{
			$this->primeCurrencyMap($persisted);
		}
		if (in_array('webform', $dictionaryFields, true))
		{
			$this->primeWebformMap($persisted);
		}
		if (in_array('type', $dictionaryFields, true))
		{
			$this->primeDealTypeMap($persisted);
		}

		foreach ($persisted as $item)
		{
			foreach ($dictionaryFields as $fieldName)
			{
				if ($fieldName === 'personType' || $fieldName === 'honorific' || ($fieldName === 'type' && !$item instanceof Deal))
				{
					continue;
				}

				$item->internalSet($fieldName, $this->resolveDictionaryRelation($item, $fieldName));
			}
		}
	}

	/**
	 * @param Item[] $items
	 */
	private function primeStageMap(array $items): void
	{
		if ($this->stageMapByCategory !== null)
		{
			return;
		}

		$categoryIds = [];
		$entityTypeCategories = [];
		foreach ($items as $item)
		{
			$entityTypeId = $item->getEntityType()->getId();
			if ($entityTypeId === OwnerType::LEAD)
			{
				continue;
			}

			$categoryId = method_exists($item, 'getCategoryId')
				? ($item->getCategoryId() ?? 0)
				: 0;
			if ($entityTypeId === OwnerType::DEAL)
			{
				$categoryIds[$categoryId] = true;
			}
			else
			{
				$entityTypeCategories[$entityTypeId][$categoryId] = true;
			}
		}

		$this->stageMapByCategory = $this->loadStageMapByCategory(array_keys($categoryIds));
		$this->stageMapByEntityType = $this->loadStageMapByEntityType($entityTypeCategories);
	}

	/**
	 * @param Item[] $items
	 */
	private function primeStageSemanticMap(array $items): void
	{
		if ($this->stageSemanticMap !== null)
		{
			return;
		}

		$semanticIdsByEntityType = [];
		foreach ($items as $item)
		{
			$semanticId = method_exists($item, 'getStageSemanticId') ? $item->getStageSemanticId() : null;
			if (is_string($semanticId) && $semanticId !== '')
			{
				$semanticIdsByEntityType[$item->getEntityType()->getId()][$semanticId] = true;
			}
		}

		$this->stageSemanticMap = $this->loadStageSemanticMap($semanticIdsByEntityType);
	}

	/**
	 * @param Item[] $items
	 */
	private function primeSourceMap(array $items): void
	{
		if ($this->sourceMap !== null)
		{
			return;
		}

		$ids = [];
		foreach ($items as $item)
		{
			$sourceId = $item->getSourceId();
			if (is_string($sourceId) && $sourceId !== '' && $sourceId !== '0')
			{
				$ids[$sourceId] = true;
			}
		}

		$this->sourceMap = $this->loadSourceMap(array_keys($ids));
	}

	/**
	 * @param Item[] $items
	 */
	private function primeCategoryMap(array $items): void
	{
		if ($this->categoryMap !== null)
		{
			return;
		}

		$idsByEntityType = [];
		foreach ($items as $item)
		{
			if (!method_exists($item, 'getCategoryId'))
			{
				continue;
			}

			$categoryId = $item->getCategoryId();
			if ($categoryId !== null)
			{
				$idsByEntityType[$item->getEntityType()->getId()][$categoryId] = true;
			}
		}

		$this->categoryMap = $this->loadCategoryMap($idsByEntityType);
	}

	/**
	 * @param Item[] $items
	 */
	private function primeCurrencyMap(array $items): void
	{
		if ($this->currencyMap !== null)
		{
			return;
		}

		$ids = [];
		foreach ($items as $item)
		{
			$currencyId = method_exists($item, 'getCurrencyId') ? $item->getCurrencyId() : null;
			if (is_string($currencyId) && $currencyId !== '')
			{
				$ids[$currencyId] = true;
			}
		}

		$this->currencyMap = $this->loadCurrencyMap(array_keys($ids));
	}

	/**
	 * @param Item[] $items
	 */
	private function primeWebformMap(array $items): void
	{
		if ($this->webformMap !== null)
		{
			return;
		}
		if (!$this->canReadWebforms())
		{
			$this->webformMap = [];

			return;
		}

		$ids = [];
		foreach ($items as $item)
		{
			$webformId = $item->getWebformId();
			if ($webformId !== null && $webformId > 0)
			{
				$ids[$webformId] = true;
			}
		}

		$this->webformMap = $this->loadWebformMap(array_keys($ids));
	}

	protected function canReadWebforms(): bool
	{
		return Container::getInstance()
			->getUserPermissions($this->accessUserId)
			->webForm()
			->canRead()
		;
	}

	/**
	 * @param Item[] $items
	 */
	private function primeDealTypeMap(array $items): void
	{
		if ($this->dealTypeMap !== null)
		{
			return;
		}

		$ids = [];
		foreach ($items as $item)
		{
			$typeId = method_exists($item, 'getTypeId') ? $item->getTypeId() : null;
			if (is_string($typeId) && $typeId !== '' && $typeId !== '0')
			{
				$ids[$typeId] = true;
			}
		}

		$this->dealTypeMap = $this->loadDealTypeMap(array_keys($ids));
	}

	private function resolveDictionaryRelation(Item $item, string $fieldName): mixed
	{
		return match ($fieldName)
		{
			'stage' => $this->resolveStage(
				$item->getEntityType()->getId(),
				$item->getEntityType()->getId() === OwnerType::LEAD
					? null
					: (method_exists($item, 'getCategoryId') ? $item->getCategoryId() : null),
				method_exists($item, 'getStageId') ? $item->getStageId() : null,
			),
			'previousStage' => $this->resolveStage(
				$item->getEntityType()->getId(),
				$item->getEntityType()->getId() === OwnerType::LEAD
					? null
					: (method_exists($item, 'getCategoryId') ? $item->getCategoryId() : null),
				method_exists($item, 'getPreviousStageId') ? $item->getPreviousStageId() : null,
			),
			'stageSemantic' => $this->resolveStageSemantic(
				$item->getEntityType()->getId(),
				method_exists($item, 'getStageSemanticId') ? $item->getStageSemanticId() : null,
			),
			'source' => $this->resolveSource($item->getSourceId()),
			'category' => $this->resolveCategory(
				$item->getEntityType()->getId(),
				method_exists($item, 'getCategoryId') ? $item->getCategoryId() : null,
			),
			'currency' => $this->resolveCurrency(
				method_exists($item, 'getCurrencyId') ? $item->getCurrencyId() : null,
			),
			'webform' => $this->resolveWebform($item->getWebformId()),
			'type' => $this->resolveDealType(
				method_exists($item, 'getTypeId') ? $item->getTypeId() : null,
			),
			default => null,
		};
	}

	private function resolveStage(int $entityTypeId, ?int $categoryId, ?string $stageId): ?Stage
	{
		if (!is_string($stageId) || $stageId === '')
		{
			return null;
		}
		if ($entityTypeId === OwnerType::LEAD)
		{
			return $this->leadStageMap[$stageId] ?? null;
		}
		if ($entityTypeId !== OwnerType::DEAL)
		{
			return $this->stageMapByEntityType[$entityTypeId][$categoryId ?? 0][$stageId] ?? null;
		}

		return $this->stageMapByCategory[$categoryId ?? 0][$stageId] ?? null;
	}

	private function primeLeadStageMap(): void
	{
		if ($this->leadStageMap !== null)
		{
			return;
		}

		$this->leadStageMap = [];
		$result = StatusTable::getList([
			'select' => ['STATUS_ID', 'NAME', 'SORT', 'COLOR', 'SEMANTICS'],
			'filter' => ['=ENTITY_ID' => 'STATUS'],
		]);
		while ($row = $result->fetch())
		{
			$this->leadStageMap[(string)$row['STATUS_ID']] = new Stage(
				id: (string)$row['STATUS_ID'],
				name: (string)$row['NAME'],
				sort: (int)$row['SORT'],
				color: $row['COLOR'] !== null ? (string)$row['COLOR'] : null,
				semanticId: $this->normalizeStageSemanticId($row['SEMANTICS'] ?? null),
			);
		}
	}

	private function resolveStageSemantic(int $entityTypeId, ?string $semanticId): ?StageSemantic
	{
		if (!is_string($semanticId) || $semanticId === '')
		{
			return null;
		}

		return $this->stageSemanticMap[$entityTypeId][$semanticId] ?? null;
	}

	private function resolveSource(?string $sourceId): ?Source
	{
		if (!is_string($sourceId) || $sourceId === '' || $sourceId === '0')
		{
			return null;
		}

		return $this->sourceMap[$sourceId] ?? null;
	}

	private function resolveCategory(int $entityTypeId, ?int $categoryId): ?Category
	{
		if ($categoryId === null)
		{
			return null;
		}

		return $this->categoryMap[$entityTypeId][$categoryId] ?? null;
	}

	private function resolveCurrency(?string $currencyId): ?Currency
	{
		if (!is_string($currencyId) || $currencyId === '')
		{
			return null;
		}

		return $this->currencyMap[$currencyId] ?? null;
	}

	private function resolveWebform(?int $webformId): ?Webform
	{
		if ($webformId === null || $webformId <= 0)
		{
			return null;
		}

		return $this->webformMap[$webformId] ?? null;
	}

	private function resolveDealType(?string $typeId): ?DealType
	{
		if (!is_string($typeId) || $typeId === '' || $typeId === '0')
		{
			return null;
		}

		return $this->dealTypeMap[$typeId] ?? null;
	}

	/**
	 * @param int[] $categoryIds
	 * @return array<int, array<string, Stage>>
	 */
	protected function loadStageMapByCategory(array $categoryIds): array
	{
		$result = [];
		$factory = Container::getInstance()->getFactory(OwnerType::DEAL);
		if ($factory === null)
		{
			return $result;
		}

		foreach (array_values(array_unique($categoryIds)) as $categoryId)
		{
			foreach ($factory->getStages($categoryId)->getAll() as $stage)
			{
				$result[$categoryId][$stage->getStatusId()] = new Stage(
					id: $stage->getStatusId(),
					name: $stage->getName(),
					sort: $stage->getSort(),
					color: $stage->getColor(),
					semanticId: $this->normalizeStageSemanticId($stage->getSemantics()),
				);
			}
		}

		return $result;
	}

	/**
	 * @param array<int, array<int, true>> $entityTypeCategories
	 * @return array<int, array<int, array<string, Stage>>>
	 */
	protected function loadStageMapByEntityType(array $entityTypeCategories): array
	{
		$result = [];
		foreach ($entityTypeCategories as $entityTypeId => $categoryIds)
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);
			if ($factory === null)
			{
				continue;
			}

			foreach (array_keys($categoryIds) as $categoryId)
			{
				foreach ($factory->getStages($categoryId)->getAll() as $stage)
				{
					$result[$entityTypeId][$categoryId][$stage->getStatusId()] = new Stage(
						id: $stage->getStatusId(),
						name: $stage->getName(),
						sort: $stage->getSort(),
						color: $stage->getColor(),
						semanticId: $this->normalizeStageSemanticId($stage->getSemantics()),
					);
				}
			}
		}

		return $result;
	}

	private function normalizeStageSemanticId(?string $semanticId): string
	{
		return PhaseSemantics::isDefined($semanticId) ? $semanticId : PhaseSemantics::PROCESS;
	}

	/**
	 * @param array<int, array<string, true>> $semanticIdsByEntityType
	 * @return array<int, array<string, StageSemantic>>
	 */
	protected function loadStageSemanticMap(array $semanticIdsByEntityType): array
	{
		$result = [];
		foreach ($semanticIdsByEntityType as $entityTypeId => $semanticIds)
		{
			$useCommonNames = !in_array($entityTypeId, [OwnerType::DEAL, OwnerType::LEAD, OwnerType::QUOTE], true);
			$items = PhaseSemantics::getListFilterInfo($entityTypeId, [], $useCommonNames)['items'] ?? [];
			foreach (array_keys($semanticIds) as $semanticId)
			{
				if (!array_key_exists($semanticId, $items))
				{
					continue;
				}

				$result[$entityTypeId][$semanticId] = new StageSemantic($semanticId, (string)$items[$semanticId]);
			}
		}

		return $result;
	}

	/**
	 * @param string[] $ids
	 * @return array<string, Source>
	 */
	protected function loadSourceMap(array $ids): array
	{
		$result = [];
		foreach ($this->loadStatusRows($ids, StatusTable::ENTITY_ID_SOURCE) as $statusId => $status)
		{
			$result[$statusId] = new Source(
				id: (string)$status['STATUS_ID'],
				name: $status['NAME'] ?? null,
				sort: isset($status['SORT']) ? (int)$status['SORT'] : null,
			);
		}

		return $result;
	}

	/**
	 * @param array<int, array<int, true>> $idsByEntityType
	 * @return array<int, array<int, Category>>
	 */
	protected function loadCategoryMap(array $idsByEntityType): array
	{
		$result = [];
		foreach ($idsByEntityType as $entityTypeId => $requestedIds)
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);
			if ($factory === null)
			{
				continue;
			}

			foreach ($factory->getCategories() as $category)
			{
				$categoryId = $category->getId();
				if (!isset($requestedIds[$categoryId]))
				{
					continue;
				}

				$result[$entityTypeId][$categoryId] = $this->createCategoryEntity($category);
			}
		}

		return $result;
	}

	/**
	 * @param string[] $ids
	 * @return array<string, Currency>
	 */
	protected function loadCurrencyMap(array $ids): array
	{
		$requestedIds = array_fill_keys($ids, true);
		$result = [];
		foreach (\CCrmCurrency::GetAll(LANGUAGE_ID) as $currencyId => $currency)
		{
			if (!isset($requestedIds[$currencyId]))
			{
				continue;
			}

			$result[$currencyId] = new Currency(
				id: (string)$currency['CURRENCY'],
				fullName: $currency['FULL_NAME'] ?? null,
				formatString: $currency['FORMAT_STRING'] ?? null,
				decPoint: $currency['DEC_POINT'] ?? null,
				thousandsSep: $currency['THOUSANDS_SEP'] ?? null,
				decimals: isset($currency['DECIMALS']) ? (int)$currency['DECIMALS'] : null,
				hideZero: isset($currency['HIDE_ZERO']) ? $currency['HIDE_ZERO'] === 'Y' : null,
				amount: isset($currency['AMOUNT']) ? (float)$currency['AMOUNT'] : null,
				amountCount: isset($currency['AMOUNT_CNT']) ? (int)$currency['AMOUNT_CNT'] : null,
				base: ($currency['BASE'] ?? 'N') === 'Y',
				sort: isset($currency['SORT']) ? (int)$currency['SORT'] : null,
				numericCode: $currency['NUMCODE'] ?? null,
				languageId: $currency['LID'] ?? null,
				createdTime: $this->normalizeDateTime($currency['DATE_CREATE'] ?? null),
				updatedTime: $this->normalizeDateTime($currency['DATE_UPDATE'] ?? null),
				createdById: isset($currency['CREATED_BY']) ? (int)$currency['CREATED_BY'] : null,
				updatedById: isset($currency['MODIFIED_BY']) ? (int)$currency['MODIFIED_BY'] : null,
			);
		}

		return $result;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, Webform>
	 */
	protected function loadWebformMap(array $ids): array
	{
		$requestedIds = array_fill_keys($ids, true);
		$result = [];
		foreach (Manager::getListNames() as $webformId => $name)
		{
			$webformId = (int)$webformId;
			if (!isset($requestedIds[$webformId]))
			{
				continue;
			}

			$result[$webformId] = new Webform($webformId, (string)$name);
		}

		return $result;
	}

	/**
	 * @param string[] $ids
	 * @return array<string, DealType>
	 */
	protected function loadDealTypeMap(array $ids): array
	{
		$result = [];
		foreach ($this->loadStatusRows($ids, StatusTable::ENTITY_ID_DEAL_TYPE) as $statusId => $status)
		{
			$result[$statusId] = new DealType(
				id: (string)$status['STATUS_ID'],
				name: $status['NAME'] ?? null,
				sort: isset($status['SORT']) ? (int)$status['SORT'] : null,
				isSystem: isset($status['SYSTEM']) ? $status['SYSTEM'] === 'Y' : null,
			);
		}

		return $result;
	}

	/**
	 * @param string[] $ids
	 * @return array<string, array<string, mixed>>
	 */
	private function loadStatusRows(array $ids, string $entityId): array
	{
		$requestedIds = array_fill_keys($ids, true);
		$result = [];
		foreach (\CCrmStatus::GetStatus($entityId) as $statusId => $status)
		{
			if (!isset($requestedIds[$statusId]))
			{
				continue;
			}

			$result[$statusId] = $status;
		}

		return $result;
	}

	private function createCategoryEntity(CrmCategory $category): Category
	{
		return new Category(
			id: $category->getId(),
			name: $category->getName(),
			sort: $category->getSort(),
			isDefault: $category->getIsDefault(),
		);
	}

	private function normalizeDateTime(mixed $value): ?\Bitrix\Main\Type\DateTime
	{
		if ($value instanceof \Bitrix\Main\Type\DateTime)
		{
			return $value;
		}

		if (is_string($value) && $value !== '')
		{
			return new \Bitrix\Main\Type\DateTime($value);
		}

		return null;
	}
}
