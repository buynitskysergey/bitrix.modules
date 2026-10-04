<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item;

use Bitrix\Crm\Currency;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\CustomFieldDtoGenerator;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Public\Entity\Item\EmailValue;
use Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement\CustomField;
use Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement\File;
use Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement\FileUpload;
use Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement\FileUploadValue;
use Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement\FieldValueElementInterface;
use Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement\Multifield;
use Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement\Relation;
use Bitrix\Crm\V2\Public\Entity\Item\ImValue;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneValue;
use Bitrix\Crm\V2\Public\Entity\Item\WebValue;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\Multifield\TypeRepository;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldDescriptor;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\CustomFieldValueConverter;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Crm\V2\Internal\Integration\Rest\DtoConverter;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\FileUploadGateway;
use Bitrix\Main\Error;
use Bitrix\Main\UserField\Types\DoubleType;
use Bitrix\Currency\CurrencyManager;

final class FieldValueElementMapper
{
	private const MULTIFIELDS = ['phone', 'email', 'web', 'im'];
	private const DEFERRED_VALIDATION_TYPES = [
		'enumeration',
		'employee',
		'crm',
		'crm_status',
		'iblock_element',
		'iblock_section',
	];

	public function __construct(
		?CustomFieldRegistry $customFieldRegistry = null,
		?CustomFieldDtoGenerator $customFieldDtoGenerator = null,
		?CustomFieldValueConverter $customFieldValueConverter = null,
		?FileUploadGateway $fileUploadGateway = null,
	)
	{
		$this->customFieldRegistry = $customFieldRegistry ?? CustomFieldRegistry::getInstance();
		$this->customFieldDtoGenerator = $customFieldDtoGenerator
			?? new CustomFieldDtoGenerator($this->customFieldRegistry)
		;
		$this->customFieldValueConverter = $customFieldValueConverter ?? new CustomFieldValueConverter();
		$this->fileUploadGateway = $fileUploadGateway ?? new FileUploadGateway();
	}

	private readonly CustomFieldRegistry $customFieldRegistry;
	private readonly CustomFieldDtoGenerator $customFieldDtoGenerator;
	private readonly CustomFieldValueConverter $customFieldValueConverter;
	private readonly FileUploadGateway $fileUploadGateway;

	public function map(
		EntityType $entityType,
		string $fieldName,
		mixed $value,
		bool $validateValueExistence = true,
	): FieldValueElementInterface
	{
		if (in_array($fieldName, self::MULTIFIELDS, true))
		{
			return $this->mapMultifield($fieldName, $value, $validateValueExistence);
		}

		if (in_array($fieldName, ['contactsId', 'companiesId', 'observersId'], true))
		{
			if (!is_int($value) || $value <= 0)
			{
				throw $this->invalidValue();
			}

			return new Relation($fieldName, $value);
		}

		$descriptor = $this->customFieldRegistry
			->getEntityDescriptorsMap($entityType)[$fieldName] ?? null
		;
		if (!$descriptor instanceof CustomFieldDescriptor)
		{
			if (is_int($value) || is_float($value) || is_string($value) || is_bool($value))
			{
				return new CustomField($fieldName, $value);
			}

			throw $this->invalidValue();
		}

		if ($descriptor->type === 'file')
		{
			if (is_int($value) && $value > 0)
			{
				return new File($fieldName, $value);
			}

			$input = $this->fileUploadGateway->createInput($value, 'value');

			return new FileUpload($fieldName, new FileUploadValue(
				$input->name,
				$input->data,
				$input->url,
				$input->path,
			));
		}

		$field = $this->customFieldDtoGenerator->generateCustomField($descriptor);
		if (!$field instanceof DtoField)
		{
			throw $this->invalidValue();
		}

		try
		{
			if (in_array($descriptor->type, ['date', 'datetime'], true) && !$this->isValidDateValue($descriptor->type, $value))
			{
				throw $this->invalidValue();
			}
			if ($validateValueExistence && $descriptor->type === 'money' && !$this->isValidMoney($value))
			{
				throw $this->invalidValue();
			}
			if ($validateValueExistence && $descriptor->type === 'address' && !$this->isValidAddress($value))
			{
				throw $this->invalidValue();
			}

			$propertyType = $field->getElementType() ?? $field->getPropertyType();
			$converted = DtoConverter::convertValueByType($propertyType, $value, $descriptor->name);
			$shouldValidateRules = $validateValueExistence
				&& !in_array($descriptor->type, self::DEFERRED_VALIDATION_TYPES, true)
			;
			foreach ($shouldValidateRules ? $field->getValidationRules() : [] as $rule)
			{
				$validationResult = $rule->validateProperty(
					$field->getElementType() !== null || $field->isMultiple() ? [$converted] : $converted,
				);
				if (!$validationResult->isSuccess())
				{
					throw $this->invalidValue();
				}
			}

			$converted = $this->customFieldValueConverter->convertElement($field, $converted);
			if ($validateValueExistence
				&& in_array($descriptor->type, ['string', 'rich_text', 'url', 'crm_status'], true)
				&& is_string($converted)
			)
			{
				$converted = trim($converted);
				if ($converted === '')
				{
					throw $this->invalidValue();
				}
			}
			if ($validateValueExistence
				&& $descriptor->type === 'double'
				&& (is_int($converted) || is_float($converted))
			)
			{
				$converted = $this->normalizeDoubleValue($entityType, $descriptor, $converted);
			}
			if ($descriptor->type === 'crm')
			{
				$converted = $this->mapCrmValue($descriptor, $value);
			}

			return new CustomField($fieldName, $converted);
		}
		catch (\Throwable)
		{
			throw $this->invalidValue();
		}
	}

	private function normalizeDoubleValue(
		EntityType $entityType,
		CustomFieldDescriptor $descriptor,
		int|float $value,
	): float
	{
		$userField = $this->customFieldRegistry->getUserFields(
			$entityType,
			defined('LANGUAGE_ID') ? LANGUAGE_ID : 'en',
			0,
		)[$descriptor->name] ?? null;
		$settings = is_array($userField) ? ($userField['SETTINGS'] ?? null) : null;
		if (!is_array($settings) || !array_key_exists('PRECISION', $settings))
		{
			return (float)$value;
		}

		return (float)DoubleType::onBeforeSave(['SETTINGS' => $settings], (string)$value);
	}

	private function mapMultifield(string $fieldName, mixed $value, bool $validateValueExistence): Multifield
	{
		if (!is_array($value)
			|| !is_string($value['valueTypeId'] ?? null)
			|| !is_string($value['value'] ?? null)
			|| $value['valueTypeId'] === ''
			|| ($validateValueExistence
				&& (trim($value['valueTypeId']) === ''
					|| !in_array($value['valueTypeId'], TypeRepository::getValueTypes(strtoupper($fieldName)), true)
				)
			)
		)
		{
			throw $this->invalidValue();
		}

		$valueType = $value['valueTypeId'];
		$multifieldValue = trim($value['value']);
		if ($validateValueExistence && $multifieldValue === '')
		{
			throw $this->invalidValue();
		}

		$valueClass = match ($fieldName)
		{
			'phone' => PhoneValue::class,
			'email' => EmailValue::class,
			'web' => WebValue::class,
			'im' => ImValue::class,
		};

		return new Multifield($fieldName, new $valueClass($valueType, $multifieldValue));
	}

	private function isValidAddress(mixed $value): bool
	{
		if (!is_array($value) || !is_string($value['address'] ?? null) || trim($value['address']) === '')
		{
			return false;
		}

		$latitude = $value['latitude'] ?? null;
		$longitude = $value['longitude'] ?? null;
		if ($latitude === null && $longitude === null)
		{
			return true;
		}

		return (is_int($latitude) || is_float($latitude))
			&& (is_int($longitude) || is_float($longitude))
			&& $latitude >= -90 && $latitude <= 90
			&& $longitude >= -180 && $longitude <= 180
		;
	}

	private function isValidDateValue(string $type, mixed $value): bool
	{
		if (!is_string($value))
		{
			return false;
		}

		$date = \DateTime::createFromFormat($type === 'date' ? 'Y-m-d' : DATE_ATOM, $value);
		$errors = \DateTime::getLastErrors();

		return $date !== false
			&& ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
		;
	}

	private function isValidMoney(mixed $value): bool
	{
		if (!is_array($value)
			|| !array_key_exists('sum', $value)
			|| !(is_int($value['sum']) || is_float($value['sum']) || (is_string($value['sum']) && is_numeric($value['sum'])))
			|| (is_float($value['sum']) && !is_finite($value['sum']))
			|| (float)$value['sum'] < 0
			|| !is_string($value['currencyId'] ?? null)
			|| !CurrencyManager::isCurrencyExist($value['currencyId'])
		)
		{
			return false;
		}

		if (is_string($value['sum'])
			&& preg_match('/^\\+?(?:\\d+(?:\\.\\d*)?|\\.\\d+)$/D', $value['sum']) !== 1
		)
		{
			return false;
		}

		return $this->getDecimalPlaces($value['sum']) <= (int)Currency::getCurrencyDecimals($value['currencyId']);
	}

	private function getDecimalPlaces(int|float|string $value): int
	{
		if (is_int($value))
		{
			return 0;
		}

		$parts = preg_split('/e/i', (string)$value, 2);
		$coefficient = $parts[0];
		$exponent = isset($parts[1]) ? (int)$parts[1] : 0;
		$decimalSeparatorPosition = strpos($coefficient, '.');
		$coefficientDecimalPlaces = $decimalSeparatorPosition === false
			? 0
			: strlen($coefficient) - $decimalSeparatorPosition - 1
		;

		return max(0, $coefficientDecimalPlaces - $exponent);
	}

	private function mapCrmValue(CustomFieldDescriptor $descriptor, mixed $value): ItemId
	{
		if (count((array)$descriptor->entityTypesId) === 1 && is_int($value))
		{
			return new ItemId(EntityType::fromId((int)$descriptor->entityTypesId[0]), $value);
		}

		return new ItemId(
			EntityType::fromId((int)$value['entityTypeId']),
			(int)$value['entityId'],
		);
	}

	private function invalidValue(): RequestValidationException
	{
		return new RequestValidationException([
			new Error('The field value is invalid.'),
		]);
	}
}
