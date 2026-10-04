<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DocumentField;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\StorageField\Converter\ValueFieldConverter;
use Bitrix\Bizproc\Internal\Service\User\UserProvider;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;

/**
 * Printable form of a stored field value: the storage item grid and the data view preview share it,
 * so the same raw value never reaches the user in two different shapes.
 */
final class FieldValueFormatter
{
	/**
	 * Types whose printable form says something the stored value does not: a user id becomes a name, a
	 * list key becomes its label, a date takes the site format. A string or a number prints as itself,
	 * so asking for its printable form changes nothing.
	 */
	private const PRESENTABLE_TYPES = [
		FieldType::BOOL,
		FieldType::DATE,
		FieldType::DATETIME,
		FieldType::TIME,
		FieldType::FILE,
		FieldType::SELECT,
		FieldType::INTERNALSELECT,
		FieldType::USER,
	];

	private function __construct(private readonly FieldType $fieldType)
	{
	}

	public static function isPresentableType(string $type): bool
	{
		return in_array($type, self::PRESENTABLE_TYPES, true);
	}

	/**
	 * @param array<string, mixed> $property bizproc field property, e.g. {@see \Bitrix\Bizproc\Internal\Entity\StorageField\StorageField::toProperty()}
	 */
	public static function forProperty(array $property): ?self
	{
		$fieldType = \CBPRuntime::getRuntime()
			->getDocumentService()
			->getFieldTypeObject(Workflow::getComplexType(), $property)
		;

		return $fieldType === null ? null : new self($fieldType);
	}

	public static function forType(string $type, bool $multiple = false): ?self
	{
		return self::forProperty(['Type' => $type, 'Multiple' => $multiple]);
	}

	/**
	 * @param string $format one of the formats the field type declares in getFormats(); an unknown one
	 *   leaves the value as it is
	 */
	public function format(mixed $value, string $format = 'printable'): string
	{
		if (\CBPHelper::isEmptyValue($value))
		{
			return '';
		}

		$formatted = $this->fieldType->formatValue($this->restoreViewerTime($value), $format);

		return is_scalar($formatted) ? (string)$formatted : '';
	}

	/**
	 * Warms up the users behind {@see \CBPHelper::usersArrayToString}, so that formatting a page of
	 * rows does not cost a query per user.
	 *
	 * @param array<mixed> $values raw values, nested arrays allowed
	 */
	public static function prefetchUsers(array $values): void
	{
		$ids = [];
		array_walk_recursive($values, static function (mixed $value) use (&$ids): void {
			if (is_string($value) && preg_match('#^user_(\d+)$#', $value, $matches))
			{
				$ids[] = (int)$matches[1];
			}
		});

		if ($ids !== [])
		{
			UserProvider::prefetch($ids);
		}
	}

	/**
	 * A date read from storage carries no viewer offset, while the same value taken from the item
	 * repository already does. {@see ValueFieldConverter::fromStorage} brings a stored string to the
	 * viewer's moment and leaves an already converted value alone.
	 */
	private function restoreViewerTime(mixed $value): mixed
	{
		$type = $this->fieldType->getBaseType();
		if ($type !== FieldType::DATE && $type !== FieldType::DATETIME)
		{
			return $value;
		}

		return is_array($value)
			? array_map(static fn (mixed $element): mixed => ValueFieldConverter::fromStorage($element, $type), $value)
			: ValueFieldConverter::fromStorage($value, $type)
		;
	}
}
