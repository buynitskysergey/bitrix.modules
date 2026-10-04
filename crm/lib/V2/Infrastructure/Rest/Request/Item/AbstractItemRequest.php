<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\CustomFieldConverter;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldsDto;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Dto\DtoFieldsCollection;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Structure;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;

abstract class AbstractItemRequest extends Request
{
	/** @var array<string, mixed> */
	private array $rawCustomFields = [];

	/** @var array<string, array{shape: string, items?: string[]}> */
	private array $rawCustomFieldJsonShapes = [];

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		$request = parent::create($httpRequest, $dtoClass, $options);

		if (isset($request->fields) && !empty($request->fields->getUserFields()))
		{
			self::validateFieldsScope($request);
			$request->rawCustomFields = $request->fields->getUserFields();
			$request->rawCustomFieldJsonShapes = self::extractCustomFieldJsonShapes(
				$request->rawCustomFields,
			);
			$dto = Structure::getDto($dtoClass);
			if ($dto === null)
			{
				return $request;
			}

			$inputCustomFields = $request->fields->getUserFields();
			foreach ($inputCustomFields as $fieldName => $fieldValue)
			{
				if (isset($dto->getFields()[$fieldName]))
				{
					$dtoField = $dto->getFields()[$fieldName];
					if (
						$dtoField->getPropertyType() !== FileDto::class
						&& $dtoField->getElementType() !== FileDto::class
					)
					{
						$inputCustomFields[$fieldName] = CustomFieldConverter::convertValueByDtoField(
							$dtoField,
							$fieldValue,
						);
					}
				}
			}
			$input = array_merge(
				$request->fields->getItems(),
				$inputCustomFields,
			);
			$input = self::normalizeFloatFields($input, $dto->getFields());

			$request->fields = FieldsStructure::create($input, $dtoClass, $request);
		}
		elseif (isset($request->fields))
		{
			self::validateFieldsScope($request);
			$dto = Structure::getDto($dtoClass);
			if ($dto !== null)
			{
				$input = $request->fields->getItems();
				$normalizedInput = self::normalizeFloatFields($input, $dto->getFields());
				if ($normalizedInput !== $input)
				{
					$request->fields = FieldsStructure::create($normalizedInput, $dtoClass, $request);
				}
			}
		}

		if (isset($request->fields))
		{
			self::validateMultifieldsShape($request->fields);
		}

		return $request;
	}

	private static function validateFieldsScope(Request $request): void
	{
		$scope = $request->getOptions()['scope'] ?? null;
		$availableFields = $scope?->fields ?? [];
		if ($availableFields === [])
		{
			return;
		}

		$dto = Structure::getDto($request->getDtoClass());
		if ($dto === null)
		{
			return;
		}

		$fields = array_merge(
			$request->fields->getItems(),
			$request->fields->getUserFields(),
		);
		foreach ($fields as $fieldName => $_)
		{
			if (!in_array($fieldName, $availableFields, true))
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), $fieldName);
			}
		}
	}

	private static function normalizeFloatFields(array $fields, DtoFieldsCollection $dtoFields): array
	{
		foreach ($fields as $fieldName => $value)
		{
			$dtoField = $dtoFields[$fieldName] ?? null;
			if ($dtoField !== null && $dtoField->getPropertyType() === 'float' && is_int($value))
			{
				$fields[$fieldName] = (float)$value;
			}
		}

		return $fields;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getRawCustomFields(): array
	{
		return $this->rawCustomFields;
	}

	/**
	 * @return array<string, array{shape: string, items?: string[]}>
	 */
	public function getRawCustomFieldJsonShapes(): array
	{
		return $this->rawCustomFieldJsonShapes;
	}

	/**
	 * @param array<string, mixed> $customFields
	 *
	 * @return array<string, array{shape: string, items?: string[]}>
	 */
	private static function extractCustomFieldJsonShapes(array $customFields): array
	{
		$result = [];
		foreach ($customFields as $fieldName => $value)
		{
			if (!str_starts_with($fieldName, 'UF_'))
			{
				continue;
			}

			$result[$fieldName] = ['shape' => self::getJsonShape($value)];
			if (is_array($value))
			{
				$result[$fieldName]['items'] = array_map(
					static fn(mixed $item): string => self::getJsonShape($item),
					$value,
				);
			}
		}

		return $result;
	}

	private static function getJsonShape(mixed $value): string
	{
		return match (true)
		{
			$value === null => 'null',
			is_array($value) && array_is_list($value) => 'array',
			is_array($value) || is_object($value) => 'object',
			default => 'scalar',
		};
		}

	private static function validateMultifieldsShape(FieldsStructure $fields): void
	{
		$total = 0;
		foreach (['phone', 'email', 'web', 'im'] as $propertyName)
		{
			$value = $fields->getItems()[$propertyName] ?? null;
			if ($value === null)
			{
				continue;
			}

			if (!is_array($value) || !array_is_list($value))
			{
				throw new InvalidRequestFieldTypeException($propertyName, MultifieldDto::class . '[]');
			}

			$count = count($value);
			if ($count > MultifieldsDto::MAX_VALUES_PER_FIELD)
			{
				throw new DtoValidationException([
					new Error(sprintf('Too many %s multifields.', $propertyName)),
				]);
			}
			$total += $count;

			foreach ($value as $itemIndex => $itemValue)
			{
				if (!is_array($itemValue))
				{
					throw new InvalidRequestFieldTypeException(
						$propertyName . '.' . $itemIndex,
						MultifieldDto::class,
					);
				}
			}
		}

		if ($total > MultifieldsDto::MAX_VALUES_TOTAL)
		{
			throw new DtoValidationException([
				new Error('Too many multifields.'),
			]);
		}
	}

	public function getRelations(): array
	{
		return [];
	}
}
