<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

final readonly class ConditionConfig
{
	/**
	 * The two ways a condition joins the next one, and the strings a template may write them as - the names of
	 * \Bitrix\Bizproc\Activity\ConditionGroup ('AND' is 0 and 'OR' is 1 there) and the numbers as strings.
	 * Read case-insensitively, the way the older sources of the generator write 'Or'.
	 */
	private const STRING_JOINERS = ['0' => 0, 'AND' => 0, '1' => 1, 'OR' => 1];

	public function __construct(
		public string $field,
		public string $operator,
		public string|int $value,
		public string $object,
		public int $joiner = 0,
	) {}

	public static function fromArray(array $data): self
	{
		if (empty($data['field']) || empty($data['operator']))
		{
			throw new \InvalidArgumentException("Condition must have 'field' and 'operator'");
		}

		if (empty($data['object']))
		{
			throw new \InvalidArgumentException("Condition must have 'object' (activity ID)");
		}

		// Every key is checked by the shape the condition of the template carries, and not by whether the key is
		// there at all: a value of another shape used to reach the constructor and answer with a TypeError, and an
		// explicit null used to be read as no key - the value of the condition became the empty string and the way
		// it joins the next one became 'And', neither of which the source said.
		foreach (['field', 'operator', 'object'] as $stringKey)
		{
			if (!is_string($data[$stringKey]))
			{
				throw new \InvalidArgumentException(sprintf(
					"Condition: '%s' must be a string, got %s",
					$stringKey,
					get_debug_type($data[$stringKey]),
				));
			}
		}

		$value = array_key_exists('value', $data) ? $data['value'] : '';
		if (!is_string($value) && !is_int($value))
		{
			throw new \InvalidArgumentException(
				"Condition: 'value' must be a string or an integer - the value as the condition of the template"
					. ' carries it, got ' . get_debug_type($value),
			);
		}

		return new self(
			field: $data['field'],
			operator: $data['operator'],
			value: $value,
			object: $data['object'],
			joiner: self::parseJoiner($data),
		);
	}

	/**
	 * How the condition joins the next one: 0 for 'and', 1 for 'or'. The designer writes it as a number, and
	 * templates made by hand carry the strings of {@see self::STRING_JOINERS} too, so both are read - and nothing
	 * else is, in either shape. The cast that used to stand here turned any value at all into one of the two
	 * without a word, and a string outside the set went the same way: '"joiner": "maybe"' joined by 'and'.
	 *
	 * The refusal names the type where the type is what went wrong and the value where the shape is right and the
	 * value is not: 'got array' says everything about a list, while 'got 2' is the whole of what is wrong with 2.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function parseJoiner(array $data): int
	{
		$joiner = array_key_exists('joiner', $data) ? $data['joiner'] : 0;

		if (is_string($joiner))
		{
			$stated = self::STRING_JOINERS[strtoupper($joiner)] ?? null;
			if ($stated === null)
			{
				throw new \InvalidArgumentException(sprintf(
					"Condition: 'joiner' written as a string must be one of [%s], got %s",
					implode(', ', array_keys(self::STRING_JOINERS)),
					var_export($joiner, true),
				));
			}

			return $stated;
		}

		if (!is_int($joiner))
		{
			throw new \InvalidArgumentException(
				"Condition: 'joiner' must be 0 or 1 - how the condition joins the next one, got "
					. get_debug_type($joiner),
			);
		}

		if ($joiner !== 0 && $joiner !== 1)
		{
			throw new \InvalidArgumentException(
				"Condition: 'joiner' must be 0 or 1 - how the condition joins the next one, got "
					. var_export($joiner, true),
			);
		}

		return $joiner;
	}

	public function toArray(): array
	{
		return [
			'field' => $this->field,
			'operator' => $this->operator,
			'value' => $this->value,
			'joiner' => $this->joiner,
			'object' => $this->object,
		];
	}
}
