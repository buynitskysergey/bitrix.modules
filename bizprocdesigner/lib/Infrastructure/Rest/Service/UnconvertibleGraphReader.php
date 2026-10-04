<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Service;

use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\PropertyHelper;
use Bitrix\Rest\V3\Dto\Validation\FieldEditableValidator;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;

/**
 * The submitted fields read the way the conversion reads them - for the one form it cannot read at all.
 *
 * FieldsStructure::fillDto() passes an element of a dto collection on as an array without checking that it
 * is one, so `blocks: ["a"]` or settings sent as an object of scalars raise a TypeError and the contour
 * answers with an internal error instead of naming the malformed field. Such a form goes to the graph
 * validators unconverted: they name it and they read plain arrays.
 *
 * Only such a form. Everything else is converted as before, and the refusals the conversion alone would
 * raise - a read-only field, an unknown nested field, a value the property cannot hold - are raised here
 * with the classes, the addresses and the order of the core, so a graph is refused the same either way.
 */
final class UnconvertibleGraphReader
{
	/**
	 * The group the write path converts in: a field that is not editable in it is one the resource answers
	 * with and refuses to be written.
	 */
	private const WRITE_GROUP = 'add';

	/** @var array<string, array<string, \ReflectionProperty>> properties of a dto class, by property name */
	private static array $properties = [];

	/** @var array<string, Dto> a dto of the class, kept to read its fields and its name off the core */
	private static array $dtos = [];

	private static ?FieldEditableValidator $writableFields = null;

	/**
	 * The submitted fields in the plain shape, or null when the conversion reads them and is the one to run.
	 *
	 * @param array $items fields as the caller sent them ({@see \Bitrix\Rest\V3\Structure\FieldsStructure::getItems()})
	 * @param string|null $dtoClass the dto the conversion would fill; null leaves the conversion in charge
	 *
	 * @throws UnknownDtoPropertyException a nested field the resource has no place for was submitted
	 * @throws DtoValidationException a read-only field of the resource was submitted
	 * @throws InvalidRequestFieldTypeException a field of the resource cannot hold the submitted value
	 */
	public static function plainFields(array $items, ?string $dtoClass): ?array
	{
		if ($dtoClass === null || !is_subclass_of($dtoClass, Dto::class))
		{
			return null;
		}

		// Asked before anything is built: on every call but a malformed one the copy below would be built and
		// thrown away, and the refusals of this reader must not reach a body the conversion judges itself.
		if (!self::conversionFallsOver($items, $dtoClass))
		{
			return null;
		}

		$readOnlyFields = [];
		$plain = self::plainItems($items, $dtoClass, '', null, $readOnlyFields);

		// After the walk, not during it: the conversion raises an unknown field while filling the dto and a
		// read-only one only once the whole of it is filled, so a body carrying both is refused by the former.
		if ($readOnlyFields !== [])
		{
			throw new DtoValidationException($readOnlyFields);
		}

		return $plain;
	}

	/**
	 * Whether the conversion is about to hand a non-array on as an array - the one form it cannot read.
	 *
	 * Walked without building anything: the answer is no on every body but a malformed one, and the copy the
	 * reader builds otherwise costs a traversal of the whole graph and a second structure alive beside it.
	 */
	private static function conversionFallsOver(array $items, string $dtoClass): bool
	{
		$properties = self::propertiesOf($dtoClass);

		foreach ($items as $name => $value)
		{
			// A property the dto has no place for: the conversion refuses it before reading anything below it.
			$property = $properties[$name] ?? null;
			if ($property === null)
			{
				continue;
			}

			$elementType = self::collectionElementType($property);
			if ($elementType === null || !is_iterable($value))
			{
				continue;
			}

			foreach ($value as $element)
			{
				if (!is_array($element) || self::conversionFallsOver($element, $elementType))
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param string $path address of these fields the way DtoValidatorHelper builds it - the whole way down,
	 *   dot separated, empty at the top
	 * @param string|null $parentField address of these fields the way fillDto() builds it - an element of a
	 *   collection is named from its own collection, without the path of its parent
	 * @param ValidationError[] $readOnlyFields refusals collected so far, added to in place
	 *
	 * @throws UnknownDtoPropertyException
	 * @throws InvalidRequestFieldTypeException
	 */
	private static function plainItems(
		array $items,
		string $dtoClass,
		string $path,
		?string $parentField,
		array &$readOnlyFields,
	): array
	{
		$properties = self::propertiesOf($dtoClass);

		$plain = [];
		foreach ($items as $name => $value)
		{
			$property = $properties[$name] ?? null;
			if ($property === null)
			{
				throw new UnknownDtoPropertyException(
					self::dtoOf($dtoClass)->getShortName(),
					$parentField === null ? (string)$name : $parentField . '.' . $name,
				);
			}

			$readOnly = self::readOnlyRefusal($dtoClass, (string)$name, $value, $path . $name);
			if ($readOnly !== null)
			{
				// Collected rather than answered with: the walk goes on because an unknown field deeper in is
				// what the conversion would have answered such a body with.
				$readOnlyFields[] = $readOnly;
			}

			$elementType = self::collectionElementType($property);
			if ($elementType !== null)
			{
				// A collection sent as something that cannot be walked leaves the conversion with an empty
				// collection instead of an error, so it leaves this reader with an empty one too.
				$plain[$name] = is_iterable($value)
					? self::plainElements($value, $elementType, (string)$name, $path, $readOnlyFields)
					: [];

				continue;
			}

			$plain[$name] = self::assignedValue($value, $property, $path . $name);
		}

		return $plain;
	}

	/**
	 * @param ValidationError[] $readOnlyFields
	 *
	 * @throws UnknownDtoPropertyException
	 * @throws InvalidRequestFieldTypeException
	 */
	private static function plainElements(
		iterable $elements,
		string $dtoClass,
		string $name,
		string $path,
		array &$readOnlyFields,
	): array
	{
		$plain = [];
		foreach ($elements as $key => $element)
		{
			if (!is_array($element))
			{
				// The value the conversion hands on as an array without being one: this is where it falls over.
				$plain[$key] = $element;

				continue;
			}

			$plain[$key] = self::plainItems(
				$element,
				$dtoClass,
				$path . $name . '.' . $key . '.',
				$name . '.' . $key,
				$readOnlyFields,
			);
		}

		return $plain;
	}

	/**
	 * The refusal the conversion answers a field that cannot be written with, or null when it can be.
	 *
	 * What read-only means is not restated here: the very validator DtoValidatorHelper runs is asked, so the
	 * two paths cannot come to disagree about which field of the resource is one.
	 */
	private static function readOnlyRefusal(
		string $dtoClass,
		string $name,
		mixed $value,
		string $address,
	): ?ValidationError
	{
		$field = self::dtoOf($dtoClass)->getFields()[$name] ?? null;
		if ($field === null)
		{
			return null;
		}

		self::$writableFields ??= new FieldEditableValidator(ValidationGroup::create(self::WRITE_GROUP));

		$refusal = self::$writableFields->validate($field->setValue($value))->getErrors()[0] ?? null;

		return $refusal === null ? null : new ValidationError($refusal->getLocalizableMessage(), $address);
	}

	/**
	 * The value the conversion would have put into the property, or the refusal it would have answered with.
	 *
	 * The conversion assigns to the typed property of the dto from a file with no strict types, so php
	 * coerces a scalar the property does not accept into one it does - a number or a boolean reaches a string
	 * property as '5' and '1' - and raises a TypeError on a value it cannot coerce at all, which the core
	 * answers as InvalidRequestFieldTypeException. Both are repeated here, or the same body would be read one
	 * way when the conversion runs and another when this reader does.
	 *
	 * The type is read from the property itself, not from the dto field: the field reports a union as
	 * 'mixed', while php coerces into it - which is exactly the case of a setting value (string|array|null).
	 *
	 * @throws InvalidRequestFieldTypeException the property cannot hold the submitted value at all
	 */
	private static function assignedValue(mixed $value, \ReflectionProperty $property, string $address): mixed
	{
		$type = $property->getType();
		$accepted = self::acceptedTypeNames($type);
		if (in_array('mixed', $accepted, true) || in_array(get_debug_type($value), $accepted, true))
		{
			return $value;
		}

		if ($value === null && $type?->allowsNull())
		{
			return null;
		}

		// The graph dto carries no numeric leaf, so a string is the only thing a scalar is coerced into here.
		if (in_array('string', $accepted, true) && (is_int($value) || is_float($value) || is_bool($value)))
		{
			return (string)$value;
		}

		throw new InvalidRequestFieldTypeException($address, self::typeName($type, $accepted));
	}

	/**
	 * The type as the core names it in the refusal. A union is named by its members: the core reads a single
	 * name off the type and has none to read on a union, which is why the one union of the graph is nullable
	 * and never reaches this refusal.
	 *
	 * @param list<string> $accepted
	 */
	private static function typeName(?\ReflectionType $type, array $accepted): string
	{
		return $type instanceof \ReflectionNamedType ? $type->getName() : implode('|', $accepted);
	}

	/**
	 * @return list<string>
	 */
	private static function acceptedTypeNames(?\ReflectionType $type): array
	{
		$names = [];
		foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $named)
		{
			if ($named instanceof \ReflectionNamedType)
			{
				$names[] = $named->getName();
			}
		}

		return $names ?: ['mixed'];
	}

	/**
	 * The dto the elements of this property are, when it is a collection of dtos - the only shape the
	 * conversion walks into, and so the only one falling over on an element that is not an array.
	 */
	private static function collectionElementType(\ReflectionProperty $property): ?string
	{
		$type = $property->getType();
		if (!$type instanceof \ReflectionNamedType || $type->getName() !== DtoCollection::class)
		{
			return null;
		}

		$attributes = $property->getAttributes(ElementType::class);
		$elementType = $attributes === [] ? null : $attributes[0]->newInstance()->type;

		return is_string($elementType) && is_subclass_of($elementType, Dto::class) ? $elementType : null;
	}

	/**
	 * @return array<string, \ReflectionProperty> read through the very helper the conversion reads them with
	 */
	private static function propertiesOf(string $dtoClass): array
	{
		if (!isset(self::$properties[$dtoClass]))
		{
			$properties = [];
			foreach (PropertyHelper::getProperties($dtoClass) as $property)
			{
				$properties[$property->getName()] = $property;
			}

			self::$properties[$dtoClass] = $properties;
		}

		return self::$properties[$dtoClass];
	}

	/**
	 * A dto of the class to read its fields and its name off, kept because building one reads the class
	 * through reflection. It is this reader's own: nothing is filled through it, and the values its fields
	 * carry are the ones the check above has just put there.
	 */
	private static function dtoOf(string $dtoClass): Dto
	{
		return self::$dtos[$dtoClass] ??= $dtoClass::create();
	}
}
