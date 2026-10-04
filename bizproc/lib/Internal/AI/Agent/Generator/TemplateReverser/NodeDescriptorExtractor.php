<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\ActivityDescriptorConfig;

/**
 * Index of visual node descriptors of a template: one 'activity_descriptors' entry per activity type
 * plus the deviation of every node that disagrees with the entry of its own type.
 *
 * Values come from the template alone, never from the activity registry: the reversed source must not
 * depend on which activity-owning modules are installed. Node positions and titles are not part of the
 * descriptor - the first are laid out by LayoutEngine, the second is kept as the 'NodeTitle' step key.
 */
final class NodeDescriptorExtractor
{
	/** @var array<string, array<string, mixed>> activity type => descriptor of the type, sorted by type */
	private array $descriptors = [];

	/** @var array<string, array<string, mixed>> node name => fields differing from the type descriptor */
	private array $deviations = [];

	/**
	 * @param array<mixed> $activities root-level activities of the template (TEMPLATE[0].Children)
	 */
	public function __construct(array $activities)
	{
		$byType = [];
		foreach ($activities as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			$type = $activity['Type'] ?? null;
			$name = $activity['Name'] ?? null;
			$node = $activity['Node'] ?? null;
			// Inner activities of a complex wrapper have no 'Node': they are not drawn on the canvas.
			if (!is_string($type) || $type === '' || !is_string($name) || !is_array($node))
			{
				continue;
			}

			$byType[$type][$name] = self::readDescriptor($node);
		}

		foreach ($byType as $type => $nodeDescriptors)
		{
			$typeDescriptor = self::pickTypeDescriptor($nodeDescriptors);
			$this->descriptors[$type] = $typeDescriptor;

			foreach ($nodeDescriptors as $name => $descriptor)
			{
				$deviation = self::diff($typeDescriptor, $descriptor);
				if ($deviation !== [])
				{
					$this->deviations[$name] = $deviation;
				}
			}
		}

		// Reversing the same template twice must give the same file, so the map has a fixed order.
		ksort($this->descriptors, SORT_STRING);
	}

	/**
	 * @return array<string, array<string, mixed>> ready for the 'activity_descriptors' section
	 */
	public function getActivityDescriptors(): array
	{
		return $this->descriptors;
	}

	/**
	 * @return array<string, mixed>|null fields for the '_node' key of the step, null when the node
	 *                                   matches the descriptor of its activity type
	 */
	public function getNodeDeviation(string $nodeName): ?array
	{
		return $this->deviations[$nodeName] ?? null;
	}

	/**
	 * @param array<string, mixed> $node the 'Node' part of an activity
	 *
	 * @return array<string, mixed>
	 */
	private static function readDescriptor(array $node): array
	{
		$inner = is_array($node['node'] ?? null) ? $node['node'] : [];

		$fields = [];
		if (array_key_exists('type', $node))
		{
			$fields['node_type'] = $node['type'];
		}
		elseif (array_key_exists('type', $inner))
		{
			$fields['node_type'] = $inner['type'];
		}
		// A present key with a null value is kept: null in the template is not the same as
		// "take it from the registry", and only a written-out null reproduces such a node.
		if (array_key_exists('icon', $inner))
		{
			$fields['icon'] = $inner['icon'];
		}
		if (array_key_exists('colorIndex', $inner))
		{
			$fields['color_index'] = $inner['colorIndex'];
		}
		if (array_key_exists('dimensions', $node))
		{
			$fields['dimensions'] = $node['dimensions'];
		}
		if (array_key_exists('ports', $node))
		{
			$fields['ports'] = $node['ports'];
		}

		return self::orderFields($fields);
	}

	/**
	 * The descriptor shared by most nodes of the type; ties go to the first node in template order.
	 * Choosing the majority keeps the number of per-node deviations minimal.
	 *
	 * @param array<string, array<string, mixed>> $nodeDescriptors
	 *
	 * @return array<string, mixed>
	 */
	private static function pickTypeDescriptor(array $nodeDescriptors): array
	{
		$variants = [];
		foreach ($nodeDescriptors as $descriptor)
		{
			foreach ($variants as $index => $variant)
			{
				if ($variant['descriptor'] === $descriptor)
				{
					$variants[$index]['count']++;
					continue 2;
				}
			}
			$variants[] = ['descriptor' => $descriptor, 'count' => 1];
		}

		$best = $variants[0];
		foreach ($variants as $variant)
		{
			if ($variant['count'] > $best['count'])
			{
				$best = $variant;
			}
		}

		return $best['descriptor'];
	}

	/**
	 * @param array<string, mixed> $typeDescriptor
	 * @param array<string, mixed> $nodeDescriptor
	 *
	 * @return array<string, mixed>
	 */
	private static function diff(array $typeDescriptor, array $nodeDescriptor): array
	{
		$deviation = [];
		foreach (ActivityDescriptorConfig::FIELDS as $field)
		{
			if (!array_key_exists($field, $nodeDescriptor))
			{
				continue;
			}

			// Strict comparison on purpose: 48 and 48.0 are written differently in template.json,
			// and the descriptor has to reproduce the node byte for byte.
			if (
				!array_key_exists($field, $typeDescriptor)
				|| $typeDescriptor[$field] !== $nodeDescriptor[$field]
			)
			{
				$deviation[$field] = $nodeDescriptor[$field];
			}
		}

		return $deviation;
	}

	/**
	 * @param array<string, mixed> $fields
	 *
	 * @return array<string, mixed>
	 */
	private static function orderFields(array $fields): array
	{
		$ordered = [];
		foreach (ActivityDescriptorConfig::FIELDS as $field)
		{
			if (array_key_exists($field, $fields))
			{
				$ordered[$field] = $fields[$field];
			}
		}

		return $ordered;
	}
}
