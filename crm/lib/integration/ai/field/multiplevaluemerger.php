<?php

namespace Bitrix\Crm\Integration\AI\Field;

use Bitrix\Crm\Field;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserField\Types\DateType;
use Bitrix\Main\UserField\Types\DoubleType;
use Bitrix\Main\UserField\Types\EnumType;
use Bitrix\Main\UserField\Types\IntegerType;

final class MultipleValueMerger
{
	public const STRING_LOOSE_KEY_MAX_LENGTH = 100;

	/**
	 * Appends unique AI values to the current multiple field value.
	 *
	 * @param Field $field
	 * @param mixed $currentValue
	 * @param array<mixed> $aiValues
	 * @return array<mixed>
	 */
	public function merge(Field $field, mixed $currentValue, array $aiValues): array
	{
		$current = $this->normalizeCurrentValue($currentValue);
		$seen = [];

		foreach ($current as $value)
		{
			foreach ($this->getKeys($field, $value) as $key)
			{
				$seen[$key] = true;
			}
		}

		$result = $current;
		foreach ($aiValues as $aiValue)
		{
			$keys = $this->getKeys($field, $aiValue);
			if ($keys === [] || $this->hasSeenKey($keys, $seen))
			{
				continue;
			}

			foreach ($keys as $key)
			{
				$seen[$key] = true;
			}

			$result[] = $aiValue;
		}

		return $result;
	}

	private function normalizeCurrentValue(mixed $currentValue): array
	{
		if (is_array($currentValue))
		{
			return array_values($currentValue);
		}

		if ($this->normalizeWhitespace($this->stringify($currentValue)) === '')
		{
			return [];
		}

		return [$currentValue];
	}

	private function getKeys(Field $field, mixed $value): array
	{
		$fieldType = $field->getType();
		$stringValue = $this->trimWhitespace($this->stringify($value));
		if ($stringValue === '')
		{
			return [];
		}

		if (
			$fieldType === EnumType::USER_TYPE_ID
			|| $fieldType === IntegerType::USER_TYPE_ID
			|| $fieldType === DoubleType::USER_TYPE_ID
		)
		{
			return $this->getNumericKeys($field, $stringValue);
		}

		if ($fieldType === DateType::USER_TYPE_ID)
		{
			return ['D:' . $this->getDateKey($value, $stringValue)];
		}

		return $this->getStringKeys($stringValue);
	}

	private function getNumericKeys(Field $field, string $value): array
	{
		$normalized = str_replace(',', '.', $this->removeWhitespace($value));
		if (!is_numeric($normalized))
		{
			return ['s:' . mb_strtolower($normalized)];
		}

		if ($field->getType() === DoubleType::USER_TYPE_ID)
		{
			$normalizedValue = DoubleType::onBeforeSave(
				['SETTINGS' => $field->getSettings()],
				$normalized,
			);
			$floatValue = (float)$normalizedValue;

			return ['d:' . ($floatValue === 0.0 ? '0' : (string)$floatValue)];
		}

		return ['i:' . (string)(int)$normalized];
	}

	private function getDateKey(mixed $value, string $stringValue): string
	{
		if ($value instanceof Date)
		{
			return $value->format('Y-m-d');
		}

		$parsed = DateTime::tryParse($stringValue);
		if ($parsed === null)
		{
			$parsed = DateTime::tryParse($stringValue, Date::getFormat());
		}

		return $parsed === null
			? 'raw:' . mb_strtolower($stringValue)
			: $parsed->format('Y-m-d');
	}

	private function getStringKeys(string $value): array
	{
		$base = mb_strtolower($this->normalizeWhitespace($value));
		$keys = ['s:' . $base];

		if (mb_strlen($base) > self::STRING_LOOSE_KEY_MAX_LENGTH)
		{
			return $keys;
		}

		$loose = preg_replace('/[^\p{L}\p{N}]+/u', '', $base) ?? $base;
		if (
			$loose !== ''
			&& $loose !== $base
			&& preg_match('/\p{N}/u', $loose) === 1
		)
		{
			$keys[] = 's:' . $loose;
		}

		return $keys;
	}

	private function hasSeenKey(array $keys, array $seen): bool
	{
		foreach ($keys as $key)
		{
			if (isset($seen[$key]))
			{
				return true;
			}
		}

		return false;
	}

	private function stringify(mixed $value): string
	{
		if ($value === null || is_array($value) || is_resource($value))
		{
			return '';
		}

		if (is_object($value) && !$value instanceof \Stringable)
		{
			return '';
		}

		return (string)$value;
	}

	private function trimWhitespace(string $value): string
	{
		return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value) ?? $value;
	}

	private function normalizeWhitespace(string $value): string
	{
		$value = $this->trimWhitespace($value);

		return preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;
	}

	private function removeWhitespace(string $value): string
	{
		return preg_replace('/[\p{Z}\s]+/u', '', $value) ?? $value;
	}
}
