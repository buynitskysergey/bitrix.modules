<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\AutocompleteRule;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\CompletionStatus;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\DescriptionType;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\Direction;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\DateTime;
use CCrmActivityDirection;
use CCrmActivityPriority;
use CCrmActivityStatus;
use CCrmContentType;
use CCrmDateTimeHelper;

/**
 * The correspondence between the columns of `b_crm_act` and the V2 model of an activity: the one place
 * where a column knows its name in the model, its type, what it is used for and how its stored value
 * turns into a value of the model and back.
 *
 * WHY ONE PLACE. Three readers need the same correspondence - the repository hydrating a model, the
 * repository projecting a model back onto columns, and the check "this column does not leave the storage
 * layer". A second copy of the map in a mapper or in a repository would be the copy that goes stale, so
 * there is none: everything that needs the correspondence asks here.
 *
 * THE CATEGORY DECIDES THE BEHAVIOUR, not the column itself:
 * - {@see self::CATEGORY_CORE} is hydrated into {@see Activity} and projected back from it;
 * - {@see self::CATEGORY_PROVIDER} never becomes a field of the common model - the serialized three go
 *   through {@see ProviderPayload} and the rest through the typed data of a provider;
 * - {@see self::CATEGORY_STORAGE_ONLY} and {@see self::CATEGORY_LEGACY_HIDDEN} stay behind the
 *   repository and reach no model at all.
 *
 * THE VALUES OF THE NON-CORE CATEGORIES ARE NOT INTERPRETED. {@see self::toFieldValue()} and
 * {@see self::toColumnValue()} convert core columns only; for everything else they hand the value over
 * as it is, because its meaning belongs to the provider or to the storage, not to this map.
 *
 * THE LIST IS COMPLETE AND CLOSED. All 47 columns of the table have a row, and the acceptance test walks
 * the field map of the ORM entity against it: a column added to the schema without a row here fails the
 * test instead of quietly becoming invisible.
 *
 * @internal
 */
final class ActivityFieldMap
{
	public const CATEGORY_CORE = 'core';
	public const CATEGORY_PROVIDER = 'provider';
	public const CATEGORY_STORAGE_ONLY = 'storageOnly';
	public const CATEGORY_LEGACY_HIDDEN = 'legacyHidden';

	public const TYPE_INT = 'int';
	public const TYPE_FLOAT = 'float';
	public const TYPE_STRING = 'string';
	public const TYPE_BOOL = 'bool';
	public const TYPE_DATETIME = 'dateTime';
	public const TYPE_ARRAY = 'array';
	public const TYPE_MIXED = 'mixed';
	public const TYPE_COMPLETION_STATUS = 'completionStatus';
	public const TYPE_DESCRIPTION_TYPE = 'descriptionType';
	public const TYPE_DIRECTION = 'direction';
	public const TYPE_AUTOCOMPLETE_RULE = 'autocompleteRule';

	/**
	 * Column of `b_crm_act` => [name in the model or null, type, category, read-only by contract].
	 *
	 * A row of the core category with a read-only name is assigned by the storage or derived from
	 * another column: the model has no setter for it and the repository never takes it from a caller.
	 */
	private const COLUMNS = [
		'ID' => [Activity::id, self::TYPE_INT, self::CATEGORY_CORE, true],
		'PROVIDER_ID' => [Activity::providerId, self::TYPE_STRING, self::CATEGORY_CORE, false],
		'PROVIDER_TYPE_ID' => [Activity::providerTypeId, self::TYPE_STRING, self::CATEGORY_CORE, false],
		'SUBJECT' => [Activity::subject, self::TYPE_STRING, self::CATEGORY_CORE, false],
		'DESCRIPTION' => [Activity::description, self::TYPE_STRING, self::CATEGORY_CORE, false],
		'DESCRIPTION_TYPE' => [Activity::descriptionType, self::TYPE_DESCRIPTION_TYPE, self::CATEGORY_CORE, false],
		'COMPLETED' => [Activity::isCompleted, self::TYPE_BOOL, self::CATEGORY_CORE, false],
		'STATUS' => [Activity::completionStatus, self::TYPE_COMPLETION_STATUS, self::CATEGORY_CORE, false],
		'RESPONSIBLE_ID' => [Activity::responsibleId, self::TYPE_INT, self::CATEGORY_CORE, false],
		'AUTHOR_ID' => [Activity::authorId, self::TYPE_INT, self::CATEGORY_CORE, false],
		'EDITOR_ID' => [Activity::editorId, self::TYPE_INT, self::CATEGORY_CORE, false],
		'PRIORITY' => [Activity::isImportant, self::TYPE_BOOL, self::CATEGORY_CORE, false],
		'DIRECTION' => [Activity::direction, self::TYPE_DIRECTION, self::CATEGORY_CORE, false],
		'CREATED' => [Activity::createdTime, self::TYPE_DATETIME, self::CATEGORY_CORE, true],
		'LAST_UPDATED' => [Activity::updatedTime, self::TYPE_DATETIME, self::CATEGORY_CORE, true],
		'START_TIME' => [Activity::startTime, self::TYPE_DATETIME, self::CATEGORY_CORE, false],
		'END_TIME' => [Activity::endTime, self::TYPE_DATETIME, self::CATEGORY_CORE, false],
		'DEADLINE' => [Activity::deadline, self::TYPE_DATETIME, self::CATEGORY_CORE, true],
		'PARENT_ID' => [Activity::parentActivityId, self::TYPE_INT, self::CATEGORY_CORE, false],
		'AUTOCOMPLETE_RULE' => [Activity::autocompleteRule, self::TYPE_AUTOCOMPLETE_RULE, self::CATEGORY_CORE, false],

		'ASSOCIATED_ENTITY_ID' => [null, self::TYPE_INT, self::CATEGORY_PROVIDER, false],
		'CALENDAR_EVENT_ID' => [null, self::TYPE_INT, self::CATEGORY_PROVIDER, false],
		'LOCATION' => [null, self::TYPE_STRING, self::CATEGORY_PROVIDER, false],
		'URN' => [null, self::TYPE_STRING, self::CATEGORY_PROVIDER, false],
		'SETTINGS' => [null, self::TYPE_ARRAY, self::CATEGORY_PROVIDER, false],
		'PROVIDER_PARAMS' => [null, self::TYPE_MIXED, self::CATEGORY_PROVIDER, false],
		'PROVIDER_DATA' => [null, self::TYPE_STRING, self::CATEGORY_PROVIDER, false],
		'ORIGINATOR_ID' => [null, self::TYPE_STRING, self::CATEGORY_PROVIDER, false],
		'ORIGIN_ID' => [null, self::TYPE_STRING, self::CATEGORY_PROVIDER, false],
		'RESULT_STATUS' => [null, self::TYPE_INT, self::CATEGORY_PROVIDER, false],
		'RESULT_STREAM' => [null, self::TYPE_INT, self::CATEGORY_PROVIDER, false],
		'RESULT_SOURCE_ID' => [null, self::TYPE_STRING, self::CATEGORY_PROVIDER, false],
		'RESULT_MARK' => [null, self::TYPE_INT, self::CATEGORY_PROVIDER, false],
		'RESULT_SUM' => [null, self::TYPE_FLOAT, self::CATEGORY_PROVIDER, false],
		'RESULT_CURRENCY_ID' => [null, self::TYPE_STRING, self::CATEGORY_PROVIDER, false],

		'OWNER_ID' => [null, self::TYPE_INT, self::CATEGORY_STORAGE_ONLY, false],
		'OWNER_TYPE_ID' => [null, self::TYPE_INT, self::CATEGORY_STORAGE_ONLY, false],
		'TYPE_ID' => [null, self::TYPE_INT, self::CATEGORY_STORAGE_ONLY, false],
		'THREAD_ID' => [null, self::TYPE_INT, self::CATEGORY_STORAGE_ONLY, false],
		'SEARCH_CONTENT' => [null, self::TYPE_STRING, self::CATEGORY_STORAGE_ONLY, false],
		'STORAGE_TYPE_ID' => [null, self::TYPE_INT, self::CATEGORY_STORAGE_ONLY, false],
		'STORAGE_ELEMENT_IDS' => [null, self::TYPE_ARRAY, self::CATEGORY_STORAGE_ONLY, false],
		// The type is the one the value travels in - the letters 'N' and 'Y' the column really holds, which is
		// what the legacy input, the check of the fields and a read of a stored state all put in the transit.
		// The ORM wraps the same column in a boolean field over that pair (`crm/lib/activity.php`), and its
		// write accepts either shape, so nothing here converts one into the other.
		'IS_HANDLEABLE' => [null, self::TYPE_STRING, self::CATEGORY_STORAGE_ONLY, false],
		'NOTIFY_TYPE' => [null, self::TYPE_INT, self::CATEGORY_STORAGE_ONLY, false],
		'NOTIFY_VALUE' => [null, self::TYPE_INT, self::CATEGORY_STORAGE_ONLY, false],

		// The type is the logical one of the audit; the column itself is a varchar nothing converts,
		// because a hidden column only ever travels through the storage layer untouched. Hidden from
		// every model of V2 is not the same as unreachable: both of them travel in the transit of the
		// storage columns, because a caller of the legacy entry point may name either and legacy writes
		// what it was named.
		'PROVIDER_GROUP_ID' => [null, self::TYPE_INT, self::CATEGORY_LEGACY_HIDDEN, false],
		'RESULT_VALUE' => [null, self::TYPE_FLOAT, self::CATEGORY_LEGACY_HIDDEN, false],
	];

	/** Serialized columns by the field of {@see ProviderPayload} that carries them. */
	private const PAYLOAD_COLUMNS = [
		ProviderPayload::settings => 'SETTINGS',
		ProviderPayload::providerParams => 'PROVIDER_PARAMS',
		ProviderPayload::providerData => 'PROVIDER_DATA',
	];

	private const NAME = 0;
	private const TYPE = 1;
	private const CATEGORY = 2;
	private const READ_ONLY = 3;

	/**
	 * Every column of the table, in the order of the audit.
	 *
	 * @return string[]
	 */
	public static function getColumns(): array
	{
		return array_keys(self::COLUMNS);
	}

	public static function hasColumn(string $column): bool
	{
		return isset(self::COLUMNS[$column]);
	}

	/**
	 * @return string[] columns of the category, in the order of the audit
	 */
	public static function getColumnsOfCategory(string $category): array
	{
		$columns = array_filter(
			self::COLUMNS,
			static fn(array $descriptor): bool => $descriptor[self::CATEGORY] === $category,
		);

		return array_keys($columns);
	}

	/**
	 * @return string[] columns hydrated into the typed model and projected back from it
	 */
	public static function getCoreColumns(): array
	{
		return self::getColumnsOfCategory(self::CATEGORY_CORE);
	}

	/**
	 * @return string[] names of the model fields that are stored in a column of the table
	 */
	public static function getFieldNames(): array
	{
		$names = [];
		foreach (self::COLUMNS as $descriptor)
		{
			if ($descriptor[self::NAME] !== null)
			{
				$names[] = $descriptor[self::NAME];
			}
		}

		return $names;
	}

	/**
	 * Name of the column in the model, or null when the column reaches no model.
	 *
	 * @throws ArgumentException on a column the table does not have.
	 */
	public static function getFieldName(string $column): ?string
	{
		return self::getDescriptor($column)[self::NAME];
	}

	/**
	 * Column the model field is stored in, or null when the field is not a column of this table
	 * (the collections of an activity live in tables of their own).
	 */
	public static function getColumn(string $fieldName): ?string
	{
		foreach (self::COLUMNS as $column => $descriptor)
		{
			if ($descriptor[self::NAME] === $fieldName)
			{
				return $column;
			}
		}

		return null;
	}

	/**
	 * @throws ArgumentException on a column the table does not have.
	 */
	public static function getCategory(string $column): string
	{
		return self::getDescriptor($column)[self::CATEGORY];
	}

	/**
	 * @throws ArgumentException on a column the table does not have.
	 */
	public static function getType(string $column): string
	{
		return self::getDescriptor($column)[self::TYPE];
	}

	/**
	 * Whether the value of the column is assigned by the storage or derived from another column, so no
	 * caller may hand it over.
	 *
	 * @throws ArgumentException on a column the table does not have.
	 */
	public static function isReadOnly(string $column): bool
	{
		return self::getDescriptor($column)[self::READ_ONLY];
	}

	/**
	 * Column the given field of the provider payload is stored in.
	 *
	 * @throws ArgumentException on a field the payload does not have.
	 */
	public static function getPayloadColumn(string $payloadField): string
	{
		if (!isset(self::PAYLOAD_COLUMNS[$payloadField]))
		{
			throw new ArgumentException("'{$payloadField}' is not a field of the provider payload");
		}

		return self::PAYLOAD_COLUMNS[$payloadField];
	}

	/**
	 * The stored value of the column as the model holds it. Values of the columns that reach no model
	 * are handed over unchanged - their meaning belongs to their owner, not to this map.
	 *
	 * @throws ArgumentException on a column the table does not have.
	 */
	public static function toFieldValue(string $column, mixed $storedValue): mixed
	{
		if (self::getCategory($column) !== self::CATEGORY_CORE)
		{
			return $storedValue;
		}

		return match ($column)
		{
			'SUBJECT', 'DESCRIPTION', 'PROVIDER_ID' => (string)$storedValue,
			'COMPLETED' => (bool)$storedValue,
			'PRIORITY' => (int)$storedValue === CCrmActivityPriority::High,
			'STATUS' => self::toCompletionStatus($storedValue),
			'DIRECTION' => self::toDirection($storedValue),
			'DESCRIPTION_TYPE' => self::toDescriptionType($storedValue),
			'AUTOCOMPLETE_RULE' => self::toAutocompleteRule($storedValue),
			'DEADLINE' => self::toDeadline($storedValue),
			// Legacy stores "no parent" as 0; keeping the zero would make the model believe it has one.
			'PARENT_ID' => (int)$storedValue > 0 ? (int)$storedValue : null,
			default => self::toScalarFieldValue(self::getType($column), $storedValue),
		};
	}

	/**
	 * The value of the model field as the column stores it.
	 *
	 * @throws ArgumentException on a column the table does not have.
	 */
	public static function toColumnValue(string $column, mixed $fieldValue): mixed
	{
		if (self::getCategory($column) !== self::CATEGORY_CORE)
		{
			return $fieldValue;
		}

		return match ($column)
		{
			'COMPLETED' => (bool)$fieldValue,
			'PRIORITY' => $fieldValue === true ? CCrmActivityPriority::High : CCrmActivityPriority::Low,
			'STATUS' => self::fromCompletionStatus($fieldValue ?? CompletionStatus::Undefined),
			'DIRECTION' => self::fromDirection($fieldValue ?? Direction::Undefined),
			'DESCRIPTION_TYPE' => self::fromDescriptionType($fieldValue ?? DescriptionType::Plain),
			'AUTOCOMPLETE_RULE' => self::fromAutocompleteRule($fieldValue ?? AutocompleteRule::None),
			'DEADLINE' => $fieldValue instanceof DateTime ? $fieldValue : self::getDeadlineSentinel(),
			'PARENT_ID' => (int)($fieldValue ?? 0),
			default => $fieldValue,
		};
	}

	/**
	 * The date the column uses instead of NULL to say "this activity has no deadline".
	 */
	public static function getDeadlineSentinel(): DateTime
	{
		return CCrmDateTimeHelper::getMaxDatabaseDateObject();
	}

	/**
	 * @return array{0: ?string, 1: string, 2: string, 3: bool}
	 * @throws ArgumentException on a column the table does not have.
	 */
	private static function getDescriptor(string $column): array
	{
		if (!isset(self::COLUMNS[$column]))
		{
			throw new ArgumentException("'{$column}' is not a column of an activity");
		}

		return self::COLUMNS[$column];
	}

	private static function toScalarFieldValue(string $type, mixed $value): mixed
	{
		if ($value === null)
		{
			return null;
		}

		return match ($type)
		{
			self::TYPE_INT => (int)$value,
			self::TYPE_FLOAT => (float)$value,
			self::TYPE_STRING => (string)$value,
			default => $value,
		};
	}

	/**
	 * Both NULL and the max-date sentinel mean "no deadline"; the repair agent of the sentinel proves
	 * that rows with either of them exist.
	 */
	private static function toDeadline(mixed $storedValue): ?DateTime
	{
		if (!$storedValue instanceof DateTime)
		{
			return null;
		}

		$sentinelYear = self::getDeadlineSentinel()->format('Y');

		return $storedValue->format('Y') === $sentinelYear ? null : $storedValue;
	}

	/**
	 * A value nothing writes any more comes back as {@see CompletionStatus::Undefined}: rows inserted
	 * around the write path carry 0 there.
	 */
	private static function toCompletionStatus(mixed $storedValue): CompletionStatus
	{
		return match ((int)$storedValue)
		{
			CCrmActivityStatus::Waiting => CompletionStatus::Waiting,
			CCrmActivityStatus::Completed => CompletionStatus::Completed,
			CCrmActivityStatus::AutoCompleted => CompletionStatus::AutoCompleted,
			default => CompletionStatus::Undefined,
		};
	}

	private static function fromCompletionStatus(CompletionStatus $status): int
	{
		return match ($status)
		{
			CompletionStatus::Undefined => CCrmActivityStatus::Undefined,
			CompletionStatus::Waiting => CCrmActivityStatus::Waiting,
			CompletionStatus::Completed => CCrmActivityStatus::Completed,
			CompletionStatus::AutoCompleted => CCrmActivityStatus::AutoCompleted,
		};
	}

	private static function toDirection(mixed $storedValue): Direction
	{
		return match ((int)$storedValue)
		{
			CCrmActivityDirection::Incoming => Direction::Incoming,
			CCrmActivityDirection::Outgoing => Direction::Outgoing,
			default => Direction::Undefined,
		};
	}

	private static function fromDirection(Direction $direction): int
	{
		return match ($direction)
		{
			Direction::Undefined => CCrmActivityDirection::Undefined,
			Direction::Incoming => CCrmActivityDirection::Incoming,
			Direction::Outgoing => CCrmActivityDirection::Outgoing,
		};
	}

	/**
	 * The column uses 0 and NULL as "not set" sentinels, and the markup of a text that says nothing
	 * about itself is the plain one.
	 */
	private static function toDescriptionType(mixed $storedValue): DescriptionType
	{
		return match ((int)$storedValue)
		{
			CCrmContentType::BBCode => DescriptionType::Bbcode,
			CCrmContentType::Html => DescriptionType::Html,
			default => DescriptionType::Plain,
		};
	}

	private static function fromDescriptionType(DescriptionType $descriptionType): int
	{
		return match ($descriptionType)
		{
			DescriptionType::Plain => CCrmContentType::PlainText,
			DescriptionType::Bbcode => CCrmContentType::BBCode,
			DescriptionType::Html => CCrmContentType::Html,
		};
	}

	/**
	 * Only one rule has ever been applied, and both of its consumers compare the column strictly with
	 * it, so any other stored value is as good as none.
	 */
	private static function toAutocompleteRule(mixed $storedValue): AutocompleteRule
	{
		return (int)$storedValue === \Bitrix\Crm\Activity\AutocompleteRule::AUTOMATION_ON_STATUS_CHANGED
			? AutocompleteRule::OnStageChanged
			: AutocompleteRule::None
		;
	}

	private static function fromAutocompleteRule(AutocompleteRule $rule): int
	{
		return match ($rule)
		{
			AutocompleteRule::None => \Bitrix\Crm\Activity\AutocompleteRule::NONE,
			AutocompleteRule::OnStageChanged => \Bitrix\Crm\Activity\AutocompleteRule::AUTOMATION_ON_STATUS_CHANGED,
		};
	}
}
