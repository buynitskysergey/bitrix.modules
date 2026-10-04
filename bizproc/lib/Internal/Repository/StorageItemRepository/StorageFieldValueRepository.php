<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\StorageItemRepository;

use Bitrix\Bizproc\Internal\Entity;
use Bitrix\Bizproc\Internal\Exception\StorageItem\CreateStorageItemException;
use Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\StorageField\Converter\ValueFieldConverter;
use Bitrix\Bizproc\Public\Service\StorageField\FieldService;

class StorageFieldValueRepository
{
	private const QUERY_CHUNK = 500;
	private array $fieldMapCache = [];

	public function __construct(
		private readonly StorageFieldRepositoryInterface $fieldRepository,
	)
	{
	}

	public function add(Entity\StorageItem\StorageItem $item): void
	{
		$this->addMultiple([$item]);
	}

	/**
	 * @param array $items
	 * @return void
	 * @throws CreateStorageItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function addMultiple(array $items): void
	{
		// Flushed as soon as a chunk fills up rather than after collecting everything: a full
		// materialization passes up to 1000 records here, and one row per record and field means
		// tens of thousands of arrays alive at once.
		$data = [];
		foreach ($items as $item)
		{
			$recordId = $item->getId();
			foreach ($this->buildFieldRows($item) as $fieldId => $rows)
			{
				foreach ($rows as $row)
				{
					$data[] = ['RECORD_ID' => $recordId, 'FIELD_ID' => $fieldId] + $row;
					if (count($data) >= self::QUERY_CHUNK)
					{
						$this->addChunk($data);
						$data = [];
					}
				}
			}
		}

		$this->addChunk($data);
	}

	/**
	 * @throws CreateStorageItemException
	 */
	private function addChunk(array $rows): void
	{
		if (!$rows)
		{
			return;
		}

		$result = StorageRecordFieldTable::addMulti($rows, true);
		if (!$result->isSuccess())
		{
			throw new CreateStorageItemException($result->getErrors()[0]->getMessage());
		}
	}

	public function sync(Entity\StorageItem\StorageItem $item): void
	{
		$this->deleteByRecordId($item->getId());
		$this->add($item);
	}

	public function deleteByRecordIds(array $recordIds): void
	{
		foreach (array_chunk($recordIds, self::QUERY_CHUNK) as $chunk)
		{
			StorageRecordFieldTable::deleteByFilter(['=RECORD_ID' => $chunk]);
		}
	}

	public function deleteByRecordId(int $recordId): void
	{
		StorageRecordFieldTable::deleteByFilter(['=RECORD_ID' => $recordId]);
	}

	/**
	 * Loads field values grouped by record ID.
	 *
	 * @param int[] $recordIds
	 * @return array<int, array<string, mixed>>
	 */
	public function loadFieldValues(array $recordIds, int $storageTypeId = 0, ?array $fieldCodeFilter = null): array
	{
		if (!$recordIds)
		{
			return [];
		}

		$fieldCodes = [];
		if ($storageTypeId > 0)
		{
			$fieldMap = $this->getFieldMap($storageTypeId);
			foreach ($recordIds as $recordId)
			{
				foreach ($fieldMap as $code => $field)
				{
					$fieldCodes[$recordId][$code] = $field->getMultiple() ? [] : null;
				}
			}
		}

		if ($fieldCodeFilter !== null && empty($fieldCodeFilter))
		{
			return $fieldCodes;
		}

		$query = StorageRecordFieldTable::query()
			->setSelect([
				'RECORD_ID',
				'VALUE',
				'VALUE_NUM',
				'CODE' => 'FIELD.CODE',
				'MULTIPLE' => 'FIELD.MULTIPLE',
				'TYPE' => 'FIELD.TYPE',
			])
			->whereIn('RECORD_ID', $recordIds)
			->setOrder([
				'RECORD_ID' => 'ASC',
				'FIELD.CODE' => 'ASC',
			])
		;

		if ($fieldCodeFilter !== null)
		{
			$query->whereIn('FIELD.CODE', $fieldCodeFilter);
		}

		$result = $query->exec();

		while ($row = $result->fetchObject())
		{
			$recordId = $row->getRecordId();
			$field = $row->getField();
			if (!$field)
			{
				continue;
			}

			$code = $field->getCode();

			$value = FieldService::isNumericFieldType($field->getType())
				? $row->getValueNum()
				: $row->getValue()
			;

			$value = ValueFieldConverter::fromStorage($value, $field->getType());

			if ($field->getMultiple())
			{
				$fieldCodes[$recordId][$code] ??= [];
				if (!\CBPHelper::isEmptyValue($value))
				{
					$fieldCodes[$recordId][$code][] = $value;
				}
			}
			else
			{
				$fieldCodes[$recordId][$code] = $value ?? null;
			}
		}

		return $fieldCodes;
	}

	private function buildFieldRows(Entity\StorageItem\StorageItem $item): array
	{
		$fieldMap = $this->getFieldMap($item->getStorageId());
		$rows = [];

		foreach ($item->getValueFields() as $code => $value)
		{
			$field = $fieldMap[$code] ?? null;
			if (!$field)
			{
				continue;
			}

			$fieldId = $field->getId();
			$storedValues = ValueFieldConverter::toStorageValues($value, $field->getType(), $field->getMultiple());

			foreach ($storedValues as $storedValue)
			{
				$rows[$fieldId][] = [
					'VALUE' => $storedValue,
					'VALUE_NUM' => FieldService::isNumericFieldType($field->getType()) ? $storedValue : null,
				];
			}
		}

		return $rows;
	}

	public function assertKnownFieldCodes(int $storageTypeId, array $valueFields): void
	{
		$unknown = array_diff_key($valueFields, $this->getFieldMap($storageTypeId));
		if (!$unknown)
		{
			return;
		}

		$unknown = array_diff_key($unknown, $this->refreshFieldMap($storageTypeId));
		if (!$unknown)
		{
			return;
		}

		throw new CreateStorageItemException(
			'Unknown field codes in storage ' . $storageTypeId . ': ' . implode(', ', array_keys($unknown))
		);
	}

	public function buildStorageValues(Entity\StorageItem\StorageItem $item): array
	{
		$values = [];
		foreach ($this->buildFieldRows($item) as $fieldId => $rows)
		{
			$values[(int)$fieldId] = array_map(static fn(array $row): string => (string)$row['VALUE'], $rows);
		}

		ksort($values);

		return $values;
	}

	public function readStorageValues(array $recordIds): array
	{
		if (!$recordIds)
		{
			return [];
		}

		$values = [];
		foreach (array_chunk($recordIds, self::QUERY_CHUNK) as $chunk)
		{
			$result = StorageRecordFieldTable::getList([
				'select' => ['RECORD_ID', 'FIELD_ID', 'VALUE'],
				'filter' => ['=RECORD_ID' => $chunk],
				'order' => ['ID' => 'ASC'],
			]);

			while ($row = $result->fetch())
			{
				$values[(int)$row['RECORD_ID']][(int)$row['FIELD_ID']][] = (string)$row['VALUE'];
			}
		}

		foreach ($values as &$recordValues)
		{
			ksort($recordValues);
		}
		unset($recordValues);

		return $values;
	}

	public function getFieldMap(int $storageTypeId): array
	{
		return $this->fieldMapCache[$storageTypeId] ??= $this->loadFieldMap($storageTypeId, true);
	}

	public function resetFieldMapCache(?int $storageTypeId = null): void
	{
		if ($storageTypeId === null)
		{
			$this->fieldMapCache = [];

			return;
		}

		unset($this->fieldMapCache[$storageTypeId]);
	}

	private function refreshFieldMap(int $storageTypeId): array
	{
		return $this->fieldMapCache[$storageTypeId] = $this->loadFieldMap($storageTypeId, false);
	}

	private function loadFieldMap(int $storageTypeId, bool $useCache): array
	{
		$fields = $this->fieldRepository->getByStorageId(
			$storageTypeId,
			[
				'ID',
				'CODE',
				'TYPE',
				'MULTIPLE',
			],
			$useCache,
		);

		$map = [];
		foreach ($fields as $field)
		{
			$map[$field->getCode()] = $field;
		}

		return $map;
	}
}
