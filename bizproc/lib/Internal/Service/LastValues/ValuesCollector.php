<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\LastValues;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Config\LastValues;
use Bitrix\Bizproc\Internal\Service\LastValues\Dto\CapturedValue;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\Main\Web\Json;

/**
 * Builds the snapshot map of a finished run: expression => CapturedValue. Keys repeat the inspector
 * expressions, values are resolved through the same runtime resolver the expression engine uses, so
 * keys and sources cannot drift apart.
 */
class ValuesCollector
{
	/**
	 * Fields the walk may look at per key the snapshot is allowed to hold. An empty value produces no key,
	 * so the number of collected keys alone would not stop the walk of a template whose fields are empty.
	 * Four lookups per key still let a sparsely filled template fill the snapshot.
	 */
	private const SCANNED_FIELDS_PER_KEY = 4;

	private LastValues $config;

	public function __construct(?LastValues $config = null)
	{
		$this->config = $config ?? new LastValues();
	}

	/**
	 * @return array<string, CapturedValue>
	 */
	public function collect(\CBPActivity $rootActivity): array
	{
		$keyLimit = $this->config->getKeyLimit();
		$sizeLimit = $this->config->getSnapshotSizeLimit();
		$scanLimit = $this->getScannedFieldsLimit();
		$scanned = 0;
		$collected = [];
		$size = strlen('{}');

		foreach ($this->enumerateFields($rootActivity) as [$source, $field])
		{
			if (count($collected) >= $keyLimit || $scanned >= $scanLimit)
			{
				break;
			}

			++$scanned;

			$captured = $this->captureValue($rootActivity, $source, $field);
			if ($captured === null)
			{
				continue;
			}

			$key = '{=' . $source . ':' . $field . '}';
			$entrySize = $this->measureEntry($key, $captured, $collected !== []);

			// the map stops growing where the snapshot limit ends: the entries beyond it would only be
			// encoded to be dropped again by shrink()
			if ($collected !== [] && $size + $entrySize > $sizeLimit)
			{
				break;
			}

			$collected[$key] = $captured;
			$size += $entrySize;
		}

		return $collected;
	}

	/**
	 * Upper bound of the walk itself, derived from the key limit: a template with thousands of fields must
	 * not pay for a full pass on every completion, whether its values are filled or empty.
	 */
	private function getScannedFieldsLimit(): int
	{
		return $this->config->getKeyLimit() * self::SCANNED_FIELDS_PER_KEY;
	}

	/**
	 * Bytes the entry adds to the encoded snapshot, measured by encoding the entry itself. The length of the
	 * value is not a substitute: Json::encode turns a quote, an apostrophe or an ampersand into six bytes,
	 * so a template holding such text would be measured several times smaller than it is stored.
	 */
	private function measureEntry(string $key, CapturedValue $value, bool $hasPrecedingEntry): int
	{
		$size = strlen(Json::encode([$key => $value])) - strlen('{}');

		return $hasPrecedingEntry ? $size + strlen(',') : $size;
	}

	/**
	 * Encodes the map dropping trailing entries, and then cutting the value of the last one left, until
	 * the result fits the snapshot size limit. The returned JSON is never longer than the limit. A map from
	 * collect() already fits, so this is the safeguard for a map assembled elsewhere or for a single entry
	 * bigger than the whole limit.
	 *
	 * @param array<string, CapturedValue> $values
	 */
	public function shrink(array $values, int $limit): string
	{
		$kept = [];
		$size = strlen('{}');

		foreach ($values as $key => $value)
		{
			$entrySize = $this->measureEntry((string)$key, $value, $kept !== []);

			if ($kept !== [] && $size + $entrySize > $limit)
			{
				break;
			}

			$kept[$key] = $value;
			$size += $entrySize;
		}

		$json = Json::encode($kept);

		// escaping makes the per-entry estimate approximate, so the result is verified and cut down
		while (strlen($json) > $limit && count($kept) > 1)
		{
			array_pop($kept);
			$json = Json::encode($kept);
		}

		if (strlen($json) > $limit && $kept !== [])
		{
			$json = $this->shrinkEntry((string)array_key_first($kept), reset($kept), $limit);
		}

		return $json;
	}

	/**
	 * The single entry left does not fit by itself: its value is cut down until the whole snapshot fits,
	 * so a value limit raised above the snapshot one cannot produce a snapshot over the storage limit.
	 * An entry that does not fit even with an empty value leaves an empty snapshot.
	 */
	private function shrinkEntry(string $key, CapturedValue $value, int $limit): string
	{
		$items = $value->multiple ? array_values((array)$value->value) : [$value->value];
		$truncated = $value->truncated;

		while ($items !== [])
		{
			$entry = new CapturedValue(
				$value->multiple ? $items : $items[0],
				$value->type,
				$value->multiple,
				$truncated,
				$value->totalCount,
			);
			$json = Json::encode([$key => $entry]);

			$excess = strlen($json) - $limit;
			if ($excess <= 0)
			{
				return $json;
			}

			$truncated = true;

			if (count($items) > 1)
			{
				array_pop($items);

				continue;
			}

			$scalar = (string)$items[0];
			$items = strlen($scalar) > $excess
				? [mb_strcut($scalar, 0, strlen($scalar) - $excess, 'UTF-8')]
				: []
			;
		}

		return '{}';
	}

	/**
	 * Yields [source, field] pairs of every addressable snapshot field, in the order of TBL-01.
	 *
	 * @return \Generator<array{string, string}>
	 */
	private function enumerateFields(\CBPActivity $rootActivity): \Generator
	{
		foreach (array_keys($rootActivity->getVariablesTypes()) as $name)
		{
			yield [SourceType::Variable, (string)$name];
		}

		foreach (array_keys($this->getConstantsTypes($rootActivity)) as $name)
		{
			yield [SourceType::Constant, (string)$name];
		}

		foreach ($rootActivity->walkRecursive() as $activity)
		{
			// properties of the root activity are the start parameters, and those are not captured
			if ($activity === $rootActivity)
			{
				continue;
			}

			$blockId = (string)$activity->getName();
			if ($blockId === '')
			{
				continue;
			}

			foreach (array_keys($activity->getPropertiesTypes()) as $property)
			{
				yield [$blockId, (string)$property];
			}
		}
	}

	private function getConstantsTypes(\CBPActivity $rootActivity): array
	{
		$templateId = (int)$rootActivity->getWorkflowTemplateId();
		if ($templateId <= 0)
		{
			return [];
		}

		return (array)\CBPWorkflowTemplateLoader::getTemplateConstants($templateId);
	}

	private function captureValue(\CBPActivity $rootActivity, string $source, string $field): ?CapturedValue
	{
		try
		{
			[$property, $value] = $rootActivity->getRuntimeProperty($source, $field, $rootActivity);

			if ($this->isEmptyValue($value))
			{
				return null;
			}

			$type = FieldType::normalizeProperty($property);
			$typeName = (string)($type['Type'] ?? '') ?: FieldType::STRING;

			return $type['Multiple']
				? $this->captureCollection($value, $typeName)
				: $this->captureSingleValue($value, $typeName)
			;
		}
		catch (\Throwable)
		{
			// a field the runtime cannot resolve is skipped, the rest of the snapshot is still collected
			return null;
		}
	}

	/**
	 * Emptiness for the snapshot: null, an empty string and a collection of those produce no key, while
	 * false is a value the run did produce and the snapshot has to carry. CBPHelper::isEmptyValue() cannot
	 * be reused here, it counts false as empty on purpose and the engine relies on that.
	 */
	private function isEmptyValue(mixed $value): bool
	{
		if (is_array($value))
		{
			return array_filter($value, static fn($item) => $item !== null && $item !== '') === [];
		}

		return $value === null || $value === '';
	}

	private function captureSingleValue(mixed $value, string $type): ?CapturedValue
	{
		// an array in a single-valued field (a document id, for one) is kept as one scalar value
		$scalar = is_array($value) ? \CBPHelper::stringify($value) : $this->toScalar($value);
		if ($scalar === null || $scalar === '')
		{
			return null;
		}

		[$scalar, $truncated] = $this->truncateScalar($scalar);

		return new CapturedValue($scalar, $type, false, $truncated);
	}

	private function captureCollection(mixed $value, string $type): ?CapturedValue
	{
		$limit = $this->config->getValueSizeLimit();
		$kept = [];
		$size = 0;
		$truncated = false;
		$totalCount = 0;
		$limitReached = false;

		foreach ($this->enumerateItems($value) as $item)
		{
			++$totalCount;

			if ($limitReached)
			{
				continue;
			}

			if ($kept !== [] && $size + $this->measure($item) > $limit)
			{
				$truncated = true;
				$limitReached = true;

				continue;
			}

			[$item, $itemTruncated] = $this->truncateScalar($item);
			$truncated = $truncated || $itemTruncated;
			$kept[] = $item;
			$size += $this->measure($item);
		}

		if ($kept === [])
		{
			return null;
		}

		// totalCount is the number of items the snapshot actually carries, before the cut by the limit:
		// items with no representation were never part of the value the client counts (DTO-01). It is
		// counted through the whole collection, so the walk goes on after the limit is reached
		return new CapturedValue($kept, $type, true, $truncated, $totalCount);
	}

	/**
	 * Yields the items of a collection that have a representation, in the order CBPHelper::flatten() lays
	 * them out: leaves only, any depth, keys dropped, a value that is not an array is a collection of one.
	 * Streaming instead of flattening keeps the memory of the capture at the items the snapshot keeps, not
	 * at the size of the collection the run produced.
	 *
	 * @return \Generator<string|int|float|bool>
	 */
	private function enumerateItems(mixed $value): \Generator
	{
		if (is_array($value))
		{
			foreach ($value as $item)
			{
				foreach ($this->enumerateItems($item) as $scalar)
				{
					yield $scalar;
				}
			}

			return;
		}

		$scalar = $this->toScalar($value);
		if ($scalar !== null && $scalar !== '')
		{
			yield $scalar;
		}
	}

	private function toScalar(mixed $value): string|int|float|bool|null
	{
		if (is_scalar($value))
		{
			return $value;
		}

		return \CBPHelper::hasStringRepresentation($value) ? (string)$value : null;
	}

	/**
	 * @param string|int|float|bool $value
	 * @return array{string|int|float|bool, bool} value and the truncation flag
	 */
	private function truncateScalar(string|int|float|bool $value): array
	{
		$limit = $this->config->getValueSizeLimit();
		if (!is_string($value) || strlen($value) <= $limit)
		{
			return [$value, false];
		}

		return [mb_strcut($value, 0, $limit), true];
	}

	private function measure(string|int|float|bool $value): int
	{
		return strlen((string)$value);
	}
}
