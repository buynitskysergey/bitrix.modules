<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Infrastructure\Rest\Dto\ConsentDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class ConsentMapper extends Mapper
{
	private static array $itemFieldMapping = [
		'id' => 'ID',
		'agreementId' => 'AGREEMENT_ID',
		'userId' => 'USER_ID',
		'ip' => 'IP',
		'url' => 'URL',
		'originId' => 'ORIGIN_ID',
		'originatorId' => 'ORIGINATOR_ID',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(ConsentDto::class);

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$collection->add($this->mapConsentItem($rawItem, $fields));
		}

		return $collection;
	}

	private function mapConsentItem(array $rawItem, array $fields): ConsentDto
	{
		$dto = new ConsentDto();
		$emptyFields = empty($fields);

		foreach (self::$itemFieldMapping as $dtoField => $dataField)
		{
			if ($emptyFields || in_array($dtoField, $fields, true) || array_key_exists($dtoField, $fields))
			{
				$dto->{$dtoField} = $this->mapValue($dtoField, $rawItem[$dataField] ?? null);
			}
		}

		return $dto;
	}

	private function mapValue(string $dtoField, mixed $value): mixed
	{
		return match ($dtoField)
		{
			'id', 'agreementId', 'userId' => $value !== null ? (int)$value : null,
			'ip' => (string)($value ?? ''),
			default => $value,
		};
	}
}
