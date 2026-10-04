<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageField\Converter;

use Bitrix\Bizproc\BaseType\Value;
use Bitrix\Bizproc\FieldType;

class ValueFieldConverter
{
	private static array $converters = [];

	public static function toStorage(mixed $value, string $type): mixed
	{
		$converter = self::getConverter($type);

		if ($converter)
		{
			return $converter->toStorage($value);
		}

		return $value;
	}

	/**
	 * Converts the whole field value (single or multiple) into a flat array of storage-ready values.
	 * Handles cases where a single input element expands into multiple values (e.g. group_* → user IDs).
	 */
	public static function toStorageValues(mixed $value, string $type, bool $isMultiple): array
	{
		$values = $isMultiple
			? (is_array($value) ? $value : [$value])
			: (is_array($value) ? array_slice($value, 0, 1) : [$value])
		;
		$result = [];

		foreach ($values as $one)
		{
			if (\CBPHelper::isEmptyValue($one))
			{
				continue;
			}

			$storedValue = self::toStorage($one, $type);

			if (is_array($storedValue))
			{
				if (!$isMultiple)
				{
					$first = current($storedValue);
					if (!\CBPHelper::isEmptyValue($first))
					{
						$result[] = $first;
					}
				}
				else
				{
					foreach ($storedValue as $item)
					{
						if (!\CBPHelper::isEmptyValue($item))
						{
							$result[] = $item;
						}
					}
				}
			}
			elseif ($storedValue !== null)
			{
				$result[] = $storedValue;
			}
		}

		return $result;
	}

	public static function fromStorage(mixed $value, string $type): mixed
	{
		$converter = self::getConverter($type);

		if ($converter)
		{
			return $converter->fromStorage($value);
		}

		return $value;
	}

	/**
	 * Converts the whole field value (single or multiple) into a flat array of read-ready values.
	 * The viewer offset is stripped first: a value read for a viewer carries it, storage does not.
	 *
	 * @return array<mixed>
	 */
	public static function toReadValues(mixed $value, string $type, bool $isMultiple): array
	{
		$stored = self::toStorageValues(self::stripViewerOffset($value), $type, $isMultiple);

		return array_map(static fn (mixed $element): mixed => self::fromStorageForRead($element, $type), $stored);
	}

	/**
	 * Date and datetime keep their storage string form: fromStorage() would wrap them into
	 * viewer-offset value objects, which is a presentation form and not a read form.
	 */
	private static function fromStorageForRead(mixed $value, string $type): mixed
	{
		if ($type === FieldType::DATE || $type === FieldType::DATETIME)
		{
			return $value;
		}

		return self::fromStorage($value, $type);
	}

	private static function stripViewerOffset(mixed $value): mixed
	{
		if (is_array($value))
		{
			return array_map(static fn (mixed $element): mixed => self::stripViewerOffset($element), $value);
		}

		if ($value instanceof Value\DateTime)
		{
			return new Value\DateTime($value->getTimestamp() - (int)\CTimeZone::GetOffset());
		}

		return $value;
	}

	private static function getConverter(string $type): ?TypeConverterInterface
	{
		if (!self::$converters)
		{
			self::registerConverters();
		}

		return self::$converters[$type] ?? null;
	}

	private static function registerConverters(): void
	{
		$converters = [
			new DateConverter(),
			new DateTimeConverter(),
			new BoolConverter(),
			new UserConverter(),
		];

		foreach ($converters as $converter)
		{
			self::$converters[$converter::getType()] = $converter;
		}
	}
}
