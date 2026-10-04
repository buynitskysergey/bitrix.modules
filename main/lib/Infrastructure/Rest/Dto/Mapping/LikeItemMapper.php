<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Infrastructure\Rest\Dto\LikeItemDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class LikeItemMapper extends Mapper
{
	private static array $itemFieldMapping = [
		'userId' => 'USER_ID',
		'voteValue' => 'VOTE_VALUE',
		'photo' => 'PHOTO',
		'photoSrc' => 'PHOTO_SRC',
		'fullName' => 'FULL_NAME',
		'url' => 'URL',
		'userType' => 'USER_TYPE',
	];

	/**
	 * Filter/request context fields: omitted when select is empty, returned only when explicitly requested.
	 */
	private static array $requestFieldMapping = [
		'entityTypeId' => 'ENTITY_TYPE_ID',
		'entityId' => 'ENTITY_ID',
		'pathToUserProfile' => 'PATH_TO_USER_PROFILE',
		'reaction' => 'REACTION',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(LikeItemDto::class);

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$collection->add($this->mapLikeItem($rawItem, $fields));
		}

		return $collection;
	}

	private function mapLikeItem(array $rawItem, array $fields): LikeItemDto
	{
		$dto = new LikeItemDto();
		$emptyFields = empty($fields);

		foreach (self::$itemFieldMapping as $dtoField => $dataField)
		{
			if ($this->shouldMapField($dtoField, $fields, defaultIncluded: true, emptyFields: $emptyFields))
			{
				$dto->{$dtoField} = $this->mapItemValue($dtoField, $rawItem[$dataField] ?? null);
			}
		}

		foreach (self::$requestFieldMapping as $dtoField => $dataField)
		{
			if ($this->shouldMapField($dtoField, $fields, defaultIncluded: false, emptyFields: $emptyFields))
			{
				$dto->{$dtoField} = $this->mapRequestValue($dtoField, $rawItem[$dataField] ?? null);
			}
		}

		return $dto;
	}

	private function shouldMapField(
		string $dtoField,
		array $fields,
		bool $defaultIncluded,
		bool $emptyFields,
	): bool
	{
		if ($emptyFields)
		{
			return $defaultIncluded;
		}

		return in_array($dtoField, $fields, true) || array_key_exists($dtoField, $fields);
	}

	private function mapItemValue(string $dtoField, mixed $value): mixed
	{
		return match ($dtoField)
		{
			'userId', 'voteValue' => $value !== null ? (int)$value : null,
			default => $value,
		};
	}

	private function mapRequestValue(string $dtoField, mixed $value): mixed
	{
		return match ($dtoField)
		{
			'entityTypeId' => (string)($value ?? ''),
			'entityId' => (int)($value ?? 0),
			default => $value,
		};
	}
}
