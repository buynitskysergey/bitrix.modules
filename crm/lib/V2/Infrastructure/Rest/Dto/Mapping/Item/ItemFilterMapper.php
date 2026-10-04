<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item;

use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\Binding\EntityContactTable;
use Bitrix\Crm\Binding\LeadContactTable;
use Bitrix\Crm\Binding\QuoteContactTable;
use Bitrix\Crm\FieldMultiTable;
use Bitrix\Crm\Integrity\DuplicateCommunicationMatchCodeTable;
use Bitrix\Crm\Model\LastCommunicationTable;
use Bitrix\Crm\Observer\Entity\ObserverTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\UtmTable;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\AddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListFilterStructure;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\Condition as OrmCondition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Structure\Filtering\Condition as RestCondition;
use Bitrix\Rest\V3\Structure\Filtering\Operator;

final class ItemFilterMapper
{
	private const MULTIFIELD_TYPES = [
		'phone' => 'PHONE',
		'email' => 'EMAIL',
		'web' => 'WEB',
		'im' => 'IM',
	];

	private const MULTIFIELD_FIELDS = [
		'value' => 'VALUE',
		'valueTypeId' => 'VALUE_TYPE',
	];

	private const UTM_CODES = [
		'source' => UtmTable::ENUM_CODE_UTM_SOURCE,
		'medium' => UtmTable::ENUM_CODE_UTM_MEDIUM,
		'campaign' => UtmTable::ENUM_CODE_UTM_CAMPAIGN,
		'content' => UtmTable::ENUM_CODE_UTM_CONTENT,
		'term' => UtmTable::ENUM_CODE_UTM_TERM,
	];

	private const LAST_COMMUNICATION_TYPES = [
		'communicationTime' => LastCommunicationTable::ENUM_LAST_TIME,
		'callTime' => LastCommunicationTable::ENUM_CALL_TIME,
		'emailTime' => LastCommunicationTable::ENUM_EMAIL_TIME,
		'imolTime' => LastCommunicationTable::ENUM_IM_OPEN_LINES_TIME,
		'webformTime' => LastCommunicationTable::ENUM_WEB_FORM_TIME,
	];

	public function __construct(
		private readonly EntityTypeSettings $entityTypeSettings,
		private readonly ItemDtoMapper $itemDtoMapper,
		private readonly CustomFieldValueConverter $customFieldValueConverter = new CustomFieldValueConverter(),
		private readonly ?string $itemDataClass = null,
	)
	{
	}

	public function map(ItemListFilterStructure $filter, Dto $dto): ConditionTree
	{
		return $this->mapGroup($filter, $dto);
	}

	private function mapGroup(ItemListFilterStructure $filter, Dto $dto): ConditionTree
	{
		$tree = (new ConditionTree())
			->logic($filter->getLogic()->value)
			->negative($filter->isNegative())
		;
		$multifieldConditions = [];

		$conditions = $filter->getConditions();
		if ($filter->getLogic()->value === ConditionTree::LOGIC_AND && !$filter->isNegative())
		{
			$conditions = $this->flattenAndConditions($filter);
		}

		foreach ($conditions as $condition)
		{
			if ($condition instanceof ItemListFilterStructure)
			{
				$tree->addCondition($this->mapGroup($condition, $dto));

				continue;
			}

			if (
				$filter->getLogic()->value === ConditionTree::LOGIC_AND
				&& $this->isMultifieldCondition($condition)
			)
			{
				[$fieldName] = explode('.', (string)$condition->getLeftOperand(), 2);
				$multifieldConditions[$fieldName][] = $condition;

				continue;
			}

			$tree->addCondition($this->mapCondition($condition, $dto));
		}

		foreach ($multifieldConditions as $fieldName => $conditions)
		{
			$tree->addCondition($this->mapMultifieldConditions($fieldName, $conditions));
		}

		return $tree;
	}

	/**
	 * Compact filters represent every condition as a positive AND group. Flattening
	 * such groups lets multifield conditions share one storage-row subquery.
	 *
	 * @return array<int, RestCondition|ItemListFilterStructure>
	 */
	private function flattenAndConditions(ItemListFilterStructure $filter): array
	{
		$conditions = [];
		foreach ($filter->getConditions() as $condition)
		{
			if (
				$condition instanceof ItemListFilterStructure
				&& $condition->getLogic()->value === ConditionTree::LOGIC_AND
				&& !$condition->isNegative()
				&& $condition->isCompactGroup()
			)
			{
				$conditions = array_merge($conditions, $this->flattenAndConditions($condition));

				continue;
			}

			$conditions[] = $condition;
		}

		return $conditions;
	}

	private function mapCondition(RestCondition $condition, Dto $dto): OrmCondition|ConditionTree
	{
		$fieldName = (string)$condition->getLeftOperand();
		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();
		if ($operator === Operator::In && !is_array($value))
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}
		if (
			$operator === Operator::In
			&& $value === []
			&& !$this->isMultifieldCondition($condition)
		)
		{
			return new OrmCondition('id', Operator::Equal->value, 0);
		}
		return match (true)
		{
			$this->isMultifieldCondition($condition) => $this->mapMultifieldConditions(
				explode('.', $fieldName, 2)[0],
				[$condition],
			),
			$fieldName === 'contactId' => $this->mapContactCondition($fieldName, $operator, $value, true),
			$fieldName === 'contactsId' => $this->mapContactCondition($fieldName, $operator, $value, false),
			$fieldName === 'observersId' => $this->mapObserverCondition($fieldName, $operator, $value),
			str_starts_with($fieldName, 'utm.') => $this->mapUtmCondition($fieldName, $operator, $value),
			str_starts_with($fieldName, 'lastCommunication.') =>
				$this->mapLastCommunicationCondition($fieldName, $operator, $value),
			str_starts_with($fieldName, 'UF_') =>
				$this->mapCustomFieldCondition($fieldName, $operator, $value, $dto),
			default => $this->mapScalarCondition($fieldName, $operator, $value),
		};
	}

	private function isMultifieldCondition(RestCondition $condition): bool
	{
		$parts = explode('.', (string)$condition->getLeftOperand(), 2);

		return count($parts) === 2
			&& isset(self::MULTIFIELD_TYPES[$parts[0]], self::MULTIFIELD_FIELDS[$parts[1]]);
	}

	/**
	 * @param RestCondition[] $conditions
	 */
	private function mapMultifieldConditions(string $fieldName, array $conditions): OrmCondition
	{
		if (!$this->entityTypeSettings->hasMultifields())
		{
			$this->throwUnsupportedFilter($fieldName, Operator::Equal, null);
		}

		if (count($conditions) === 1 && $fieldName === 'phone')
		{
			$condition = $conditions[0];
			$fieldParts = explode('.', (string)$condition->getLeftOperand(), 2);
			if ($fieldParts[1] === 'value')
			{
				return new OrmCondition(
					'id',
					'in',
					$this->createPhoneIndexQuery(
						$condition->getOperator(),
						$condition->getRightOperand(),
						(string)$condition->getLeftOperand(),
					),
				);
			}
		}

		$query = FieldMultiTable::query()
			->setSelect(['ELEMENT_ID'])
			->where(
				'ENTITY_ID',
				\CCrmOwnerType::ResolveName($this->entityTypeSettings->getEntityType()->getId()),
			)
			->where('TYPE_ID', self::MULTIFIELD_TYPES[$fieldName])
		;

		foreach ($conditions as $condition)
		{
			$fieldParts = explode('.', (string)$condition->getLeftOperand(), 2);
			$operator = $condition->getOperator();
			$value = $condition->getRightOperand();
			$storageField = self::MULTIFIELD_FIELDS[$fieldParts[1]];

			if ($fieldName === 'phone' && $fieldParts[1] === 'value')
			{
				$this->addPhoneValueCondition($query, $operator, $value, (string)$condition->getLeftOperand());

				continue;
			}

			if ($operator === Operator::Equal)
			{
				$query->where($storageField, '=', $value);

				continue;
			}

			if ($operator === Operator::In && is_array($value) && $value !== [])
			{
				$query->whereIn($storageField, $value);

				continue;
			}

			$this->throwUnsupportedFilter((string)$condition->getLeftOperand(), $operator, $value);
		}

		return new OrmCondition('id', 'in', $query);
	}

	private function addPhoneValueCondition(
		Query $query,
		Operator $operator,
		mixed $value,
		string $fieldName,
	): void
	{
		$query->addCondition(new OrmCondition(
			'ELEMENT_ID',
			'in',
			$this->createPhoneIndexQuery($operator, $value, $fieldName),
		));
	}

	private function createPhoneIndexQuery(Operator $operator, mixed $value, string $fieldName): Query
	{
		if (!in_array($operator, [Operator::Equal, Operator::In], true))
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}

		$normalizedValues = [];
		foreach (is_array($value) ? $value : [$value] as $phone)
		{
			$normalizedPhone = \Bitrix\Crm\Integrity\DuplicateCommunicationCriterion::normalizePhone($phone);
			if ($normalizedPhone !== '')
			{
				$normalizedValues[] = $normalizedPhone;
			}
		}
		$normalizedValues = array_values(array_unique($normalizedValues));
		if ($normalizedValues === [])
		{
			throw new InvalidFilterException([$fieldName, $operator->value, $value]);
		}

		return DuplicateCommunicationMatchCodeTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', $this->entityTypeSettings->getEntityType()->getId())
			->where('TYPE', 'PHONE')
			->whereIn('VALUE', $normalizedValues)
		;
	}

	private function mapScalarCondition(string $fieldName, Operator $operator, mixed $value): OrmCondition
	{
		if ($operator === Operator::In && $value === [])
		{
			return new OrmCondition('id', Operator::Equal->value, 0);
		}

		if (
			in_array($fieldName, ['beginTime', 'closeTime'], true)
			&& !$this->entityTypeSettings->hasBeginCloseDates()
		)
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}

		$itemFieldName = $this->itemDtoMapper->mapDtoFieldNameToItem($fieldName);
		if ($itemFieldName === null)
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}

		return new OrmCondition($itemFieldName, $operator->value, $value);
	}

	private function mapContactCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
		bool $isPrimary,
	): OrmCondition|ConditionTree
	{
		if (!$this->entityTypeSettings->hasContactBindings())
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}
		$this->requireBindingOperator($fieldName, $operator, $value);

		$query = $this->createContactBindingQuery();
		if ($value !== null)
		{
			$this->applyBindingValueFilter($query, 'CONTACT_ID', $operator, $value);
		}
		if ($isPrimary)
		{
			$query->where('IS_PRIMARY', true);
		}

		return $this->createIdSubqueryCondition(
			$query,
			$this->shouldNegateBindingSubquery($operator, $value),
		);
	}

	private function createContactBindingQuery(): Query
	{
		$entityTypeId = $this->entityTypeSettings->getEntityType()->getId();
		[$tableClass, $ownerField] = match ($entityTypeId)
		{
			OwnerType::DEAL => [DealContactTable::class, 'DEAL_ID'],
			OwnerType::LEAD => [LeadContactTable::class, 'LEAD_ID'],
			OwnerType::QUOTE => [QuoteContactTable::class, 'QUOTE_ID'],
			OwnerType::COMPANY => [ContactCompanyTable::class, 'COMPANY_ID'],
			default => [EntityContactTable::class, 'ENTITY_ID'],
		};

		/** @var class-string<DataManager> $tableClass */
		$query = $tableClass::query()->setSelect([$ownerField]);
		if ($tableClass === EntityContactTable::class)
		{
			$query->where('ENTITY_TYPE_ID', $entityTypeId);
		}

		return $query;
	}

	private function mapObserverCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
	): OrmCondition|ConditionTree
	{
		if (!$this->entityTypeSettings->hasObservers())
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}
		$this->requireBindingOperator($fieldName, $operator, $value);

		$query = ObserverTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', $this->entityTypeSettings->getEntityType()->getId())
		;
		if ($value !== null)
		{
			$this->applyBindingValueFilter($query, 'USER_ID', $operator, $value);
		}

		return $this->createIdSubqueryCondition(
			$query,
			$this->shouldNegateBindingSubquery($operator, $value),
		);
	}

	private function mapUtmCondition(string $fieldName, Operator $operator, mixed $value): OrmCondition|ConditionTree
	{
		if (!$this->entityTypeSettings->hasCrmTracking())
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}

		$childName = substr($fieldName, strlen('utm.'));
		$query = UtmTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', $this->entityTypeSettings->getEntityType()->getId())
			->where('CODE', self::UTM_CODES[$childName])
		;

		if ($value === null)
		{
			$query->where('VALUE', '!=', '');

			return $this->createIdSubqueryCondition($query, $operator === Operator::Equal);
		}

		$query->where('VALUE', $operator === Operator::NotEqual ? '=' : $operator->value, $value);

		return $this->createIdSubqueryCondition($query, $operator === Operator::NotEqual);
	}

	private function mapLastCommunicationCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
	): OrmCondition|ConditionTree
	{
		$childName = substr($fieldName, strlen('lastCommunication.'));
		$query = LastCommunicationTable::query()
			->setSelect(['ENTITY_ID'])
			->where('ENTITY_TYPE_ID', $this->entityTypeSettings->getEntityType()->getId())
			->where('TYPE', self::LAST_COMMUNICATION_TYPES[$childName])
		;

		if ($value === null)
		{
			$query->whereNotNull('LAST_COMMUNICATION_TIME');

			return $this->createIdSubqueryCondition($query, $operator === Operator::Equal);
		}

		$query->where(
			'LAST_COMMUNICATION_TIME',
			$operator === Operator::NotEqual ? '=' : $operator->value,
			$value,
		);

		return $this->createIdSubqueryCondition($query, $operator === Operator::NotEqual);
	}

	private function mapCustomFieldCondition(
		string $fieldName,
		Operator $operator,
		mixed $value,
		Dto $dto,
	): OrmCondition|ConditionTree
	{
		/** @var DtoField $field */
		$field = $dto->getFields()[$fieldName];
		if ($operator === Operator::In && is_array($value))
		{
			$value = array_map(
				fn(mixed $item): mixed => $this->customFieldValueConverter->convertElement($field, $item),
				$value,
			);
		}
		else
		{
			$value = $this->customFieldValueConverter->convert($field, $value);
		}

		if ($this->isAddressField($field))
		{
			return $this->mapAddressCondition($field, $fieldName, $operator, $value);
		}

		if (
			$operator === Operator::NotEqual
			&& ($field->isMultiple() || $field->getElementType() !== null)
		)
		{
			return (new ConditionTree())
				->addCondition(new OrmCondition($fieldName, Operator::Equal->value, $value))
				->negative()
			;
		}

		return new OrmCondition($fieldName, $operator->value, $value);
	}

	private function isAddressField(DtoField $field): bool
	{
		$propertyType = $field->getElementType() ?? $field->getPropertyType();

		return is_a($propertyType, AddressDto::class, true);
	}

	private function mapAddressCondition(
		DtoField $field,
		string $fieldName,
		Operator $operator,
		mixed $value,
	): OrmCondition|ConditionTree
	{
		$values = $operator === Operator::In ? $value : [$value];
		$isMultiple = $field->isMultiple() || $field->getElementType() !== null;
		$match = $this->createAddressMatch(
			$isMultiple ? $fieldName . '_SINGLE' : $fieldName,
			$values,
		);
		if ($isMultiple)
		{
			$dataClass = $this->itemDataClass;
			if ($dataClass === null)
			{
				$factory = Container::getInstance()->getFactory(
					$this->entityTypeSettings->getEntityType()->getId(),
				);
				if ($factory === null)
				{
					$this->throwUnsupportedFilter($fieldName, $operator, $value);
				}

				$dataClass = $factory->getDataClass();
			}

			$query = $dataClass::query()
				->setSelect(['ID'])
				->where($match)
			;

			return $this->createIdSubqueryCondition($query, $operator === Operator::NotEqual);
		}

		if ($operator === Operator::NotEqual)
		{
			$match->negative();
		}

		return $match;
	}

	private function createAddressMatch(string $fieldName, array $values): ConditionTree
	{
		$match = (new ConditionTree())->logic(ConditionTree::LOGIC_OR);
		$sqlHelper = Application::getConnection()->getSqlHelper();
		foreach ($values as $visibleValue)
		{
			$visibleValue = (string)$visibleValue;
			$valueWithSeparator = $visibleValue . '|';
			$prefixLength = mb_strlen($valueWithSeparator);
			$prefix = new ExpressionField(
				'REST_' . $fieldName . '_PREFIX_' . $prefixLength,
				$sqlHelper->getSubstrFunction('%s', 1, $prefixLength),
				$fieldName,
			);

			$match
				->addCondition(new OrmCondition($fieldName, Operator::Equal->value, $visibleValue))
				->addCondition(new OrmCondition($prefix, Operator::Equal->value, $valueWithSeparator))
			;
		}

		return $match;
	}

	private function requireBindingOperator(string $fieldName, Operator $operator, mixed $value): void
	{
		if (!in_array($operator, [Operator::Equal, Operator::NotEqual, Operator::In], true))
		{
			$this->throwUnsupportedFilter($fieldName, $operator, $value);
		}
	}

	private function applyBindingValueFilter(Query $query, string $fieldName, Operator $operator, mixed $value): void
	{
		if ($operator === Operator::In)
		{
			$query->whereIn($fieldName, $value);

			return;
		}

		$query->where($fieldName, '=', $value);
	}

	private function createIdSubqueryCondition(Query $query, bool $negative): OrmCondition|ConditionTree
	{
		$condition = new OrmCondition('id', 'in', $query);
		if (!$negative)
		{
			return $condition;
		}

		return (new ConditionTree())->addCondition($condition)->negative();
	}

	private function shouldNegateBindingSubquery(Operator $operator, mixed $value): bool
	{
		return $value === null
			? $operator === Operator::Equal
			: $operator === Operator::NotEqual
		;
	}

	private function throwUnsupportedFilter(string $fieldName, Operator $operator, mixed $value): never
	{
		throw new InvalidFilterException([$fieldName, $operator->value, $value]);
	}
}
