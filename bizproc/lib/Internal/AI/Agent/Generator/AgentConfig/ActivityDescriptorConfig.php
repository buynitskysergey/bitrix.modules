<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityPortType;

/**
 * Visual descriptor of a node: the part of the node the builder would otherwise read from the
 * activity registry, i.e. from the environment. An unspecified field is taken from the registry
 * instead - see hasIcon() and hasColorIndex() for the two fields that tell an unspecified field from
 * one deliberately set to null. The other three state no null at all - see statedField(). Node
 * position is not part of the descriptor: it is laid out by LayoutEngine.
 */
final class ActivityDescriptorConfig
{
	/** Descriptor fields in the order the source format writes them. */
	public const FIELDS = ['node_type', 'icon', 'color_index', 'dimensions', 'ports'];

	public function __construct(
		public readonly ?ActivityNodeType $nodeType = null,
		public readonly ?string $icon = null,
		public readonly ?int $colorIndex = null,
		/** @var array{width: int|float|null, height: int|float|null}|null */
		public readonly ?array $dimensions = null,
		/** @var list<array<string, mixed>>|null */
		public readonly ?array $ports = null,
		/**
		 * A missing field and a field explicitly set to null are different things: the first is taken
		 * from the registry, the second means null in the template. Nodes with null icon or color
		 * index exist in shipped templates, so both fields carry a flag for the explicit-null case.
		 */
		public readonly bool $iconIsExplicitNull = false,
		public readonly bool $colorIndexIsExplicitNull = false,
	) {}

	/**
	 * @param string $context descriptor location, used in error messages
	 */
	public static function fromMixed(string $context, mixed $data): self
	{
		if (!is_array($data))
		{
			throw new \InvalidArgumentException("$context must be an object, got " . get_debug_type($data));
		}

		$unknownFields = array_diff(array_keys($data), self::FIELDS);
		if (!empty($unknownFields))
		{
			throw new \InvalidArgumentException(sprintf(
				'%s: unknown field(s) [%s]. Allowed: %s',
				$context,
				implode(', ', $unknownFields),
				implode(', ', self::FIELDS),
			));
		}

		return new self(
			nodeType: self::parseNodeType($context, self::statedField($context, $data, 'node_type')),
			icon: self::parseIcon($context, $data['icon'] ?? null),
			colorIndex: self::parseColorIndex($context, $data['color_index'] ?? null),
			dimensions: self::parseDimensions($context, self::statedField($context, $data, 'dimensions')),
			ports: self::parsePorts($context, self::statedField($context, $data, 'ports')),
			iconIsExplicitNull: self::isExplicitNull($data, 'icon'),
			colorIndexIsExplicitNull: self::isExplicitNull($data, 'color_index'),
		);
	}

	/**
	 * The value of a descriptor field the source states, and null where it states none. A field written as null is
	 * refused instead: null is a value of 'icon' and 'color_index' alone - a node in the template does carry it
	 * there, which is why the two are told apart from an unstated field by a flag of their own - while the node
	 * type, the dimensions and the ports are taken from the registry of activities whenever the source states
	 * none. A null under them could never be written into a node, so reading it as no key at all made the node
	 * depend on the environment of the build: the very thing a descriptor is written down to keep it out of.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function statedField(string $context, array $data, string $field): mixed
	{
		if (array_key_exists($field, $data) && $data[$field] === null)
		{
			throw new \InvalidArgumentException(sprintf(
				"%s: '%s' cannot be null - the field comes from the activity registry wherever the descriptor"
					. ' states none, so a node carrying null there is nothing the build can write. Leave the field'
					. ' out to take it from the registry',
				$context,
				$field,
			));
		}

		return $data[$field] ?? null;
	}

	public function hasIcon(): bool
	{
		return $this->icon !== null || $this->iconIsExplicitNull;
	}

	public function hasColorIndex(): bool
	{
		return $this->colorIndex !== null || $this->colorIndexIsExplicitNull;
	}

	/**
	 * Own fields win, the rest is taken from $base.
	 */
	public function mergeOver(?self $base): self
	{
		if ($base === null)
		{
			return $this;
		}

		return new self(
			nodeType: $this->nodeType ?? $base->nodeType,
			icon: $this->hasIcon() ? $this->icon : $base->icon,
			colorIndex: $this->hasColorIndex() ? $this->colorIndex : $base->colorIndex,
			dimensions: $this->dimensions ?? $base->dimensions,
			ports: $this->ports ?? $base->ports,
			iconIsExplicitNull: $this->hasIcon() ? $this->iconIsExplicitNull : $base->iconIsExplicitNull,
			colorIndexIsExplicitNull: $this->hasColorIndex()
				? $this->colorIndexIsExplicitNull
				: $base->colorIndexIsExplicitNull,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function isExplicitNull(array $data, string $field): bool
	{
		return array_key_exists($field, $data) && $data[$field] === null;
	}

	private static function parseNodeType(string $context, mixed $value): ?ActivityNodeType
	{
		if ($value === null)
		{
			return null;
		}

		$nodeType = is_string($value) ? ActivityNodeType::tryFrom($value) : null;
		if ($nodeType === null)
		{
			throw new \InvalidArgumentException(sprintf(
				"%s: invalid 'node_type' %s. Expected one of: %s",
				$context,
				var_export($value, true),
				implode(', ', array_column(ActivityNodeType::cases(), 'value')),
			));
		}

		return $nodeType;
	}

	private static function parseIcon(string $context, mixed $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		if (!is_string($value))
		{
			throw new \InvalidArgumentException("$context: 'icon' must be a string, got " . get_debug_type($value));
		}

		return $value;
	}

	private static function parseColorIndex(string $context, mixed $value): ?int
	{
		if ($value === null)
		{
			return null;
		}

		if (!is_int($value))
		{
			throw new \InvalidArgumentException(
				"$context: 'color_index' must be an integer, got " . get_debug_type($value),
			);
		}

		return $value;
	}

	/**
	 * Kept exactly as written: the builder must reproduce the committed template byte for byte,
	 * and dimensions there hold integers, floats and nulls alike.
	 *
	 * @return array{width: int|float|null, height: int|float|null}|null
	 */
	private static function parseDimensions(string $context, mixed $value): ?array
	{
		if ($value === null)
		{
			return null;
		}

		if (!is_array($value))
		{
			throw new \InvalidArgumentException(
				"$context: 'dimensions' must be an object with 'width' and 'height', got " . get_debug_type($value),
			);
		}

		foreach (['width', 'height'] as $side)
		{
			if (!array_key_exists($side, $value))
			{
				throw new \InvalidArgumentException("$context: 'dimensions' must contain '$side'");
			}

			if ($value[$side] !== null && !is_int($value[$side]) && !is_float($value[$side]))
			{
				throw new \InvalidArgumentException(sprintf(
					"%s: 'dimensions.%s' must be a number or null, got %s",
					$context,
					$side,
					get_debug_type($value[$side]),
				));
			}
		}

		return $value;
	}

	/**
	 * Ports are passed through as written, because their shape differs across activities
	 * (title, isActive and position are optional). Checked, and never amended: the template is compared
	 * byte for byte, so a port the source states wrong is a refusal and not a value to correct.
	 *
	 * 'id' and 'type' are the fields the port is checked by, because that is what the built node is read
	 * by: TemplateBuilder enters a binding into the first port of type 'topAux' and adds a port of a
	 * construct only when no port carries that id. A descriptor stating every field of TPL-03 makes the
	 * node trustworthy even when its activity does not resolve here, so a port the canvas cannot classify
	 * would otherwise reach the delivered template with links leading into it.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private static function parsePorts(string $context, mixed $value): ?array
	{
		if ($value === null)
		{
			return null;
		}

		if (!is_array($value) || !array_is_list($value))
		{
			throw new \InvalidArgumentException(
				"$context: 'ports' must be a list of port objects, got " . get_debug_type($value),
			);
		}

		$declaredIds = [];
		foreach ($value as $index => $port)
		{
			if (!is_array($port))
			{
				throw new \InvalidArgumentException(
					"$context: 'ports[$index]' must be an object, got " . get_debug_type($port),
				);
			}

			if (!isset($port['id']) || !is_string($port['id']) || $port['id'] === '')
			{
				throw new \InvalidArgumentException("$context: 'ports[$index]' must have a non-empty string 'id'");
			}

			if (isset($declaredIds[$port['id']]))
			{
				throw new \InvalidArgumentException(sprintf(
					"%s: 'ports[%d]' repeats the id '%s' of 'ports[%d]' - the ports of a node are told apart by"
						. ' their id',
					$context,
					$index,
					$port['id'],
					$declaredIds[$port['id']],
				));
			}
			$declaredIds[$port['id']] = $index;

			self::assertPortType($context, $index, $port['type'] ?? null);
		}

		return $value;
	}

	private static function assertPortType(string $context, int $index, mixed $value): void
	{
		if (is_string($value) && ActivityPortType::tryFrom($value) !== null)
		{
			return;
		}

		throw new \InvalidArgumentException(sprintf(
			"%s: 'ports[%d]' must have a 'type', got %s. Expected one of: %s",
			$context,
			$index,
			var_export($value, true),
			implode(', ', array_column(ActivityPortType::cases(), 'value')),
		));
	}
}
