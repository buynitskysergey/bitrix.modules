<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\ActivityDescriptorConfig;

/**
 * Tells the visual descriptors of template.source.json from the activity descriptions of this
 * environment.
 *
 * A descriptor is a snapshot, taken so that the build does not depend on which activity-owning modules
 * are installed, and it wins over the registry for exactly that reason. The truth about how a node
 * looks still belongs to the description in the owning module, so the snapshot can go stale: the module
 * changed the icon or the color, the source never heard of it, and the delivered template keeps the old
 * value. This class only makes that visible - the priority of the source stays as it is, or the shipped
 * template would change by itself whenever a foreign module is updated.
 */
final class DescriptorRegistryDrift
{
	/**
	 * Fields of a complex wrapper the builder puts on the node itself, which leaves the description of the
	 * activity no base to compare them with: the descriptor of a wrapper is a snapshot of the assembled
	 * node - with the exit port 'o1' and the height the wrapper gets in TemplateBuilder when the descriptor
	 * states neither - while the description of the activity knows the outer activity alone. Compared as
	 * they are, both fields would differ on every generation of every agent, and a check that always speaks
	 * is a check nobody reads.
	 *
	 * The blind spot of leaving them out: should the module owning a wrapper really change its ports or its
	 * size, this comparison will stay silent about it. The other three fields of a wrapper - node_type, icon
	 * and color_index - are compared as for any other type.
	 */
	private const WRAPPER_FIELDS_BUILT_ON_TOP = ['dimensions', 'ports'];

	public function __construct(
		private readonly ActivityRegistry $registry,
	) {}

	/**
	 * Differences between the descriptors and the descriptions, one line per field, ordered by activity
	 * type and then by field name - two runs over the same source have to read the same.
	 *
	 * A type this environment cannot describe is left out: there is nothing to compare its descriptor
	 * with. Types the source does not describe are not looked up either - the subject here is the
	 * snapshot, not the completeness of the source. Only the fields the descriptor states are compared:
	 * the rest is taken from the registry anyway and cannot drift.
	 *
	 * @param array<string, ActivityDescriptorConfig> $descriptors activity type => descriptor
	 * @return list<string>
	 */
	public function describe(array $descriptors): array
	{
		ksort($descriptors, SORT_STRING);

		$differences = [];
		foreach ($descriptors as $activityType => $descriptor)
		{
			if (!$this->registry->hasDescription($activityType))
			{
				continue;
			}

			foreach ($this->collectStatedFields($activityType, $descriptor) as $field => [$inSource, $inDescription])
			{
				if ($this->canonicalize($inSource) !== $this->canonicalize($inDescription))
				{
					$differences[] = sprintf(
						'%s: %s - source %s, description %s',
						$activityType,
						$field,
						$this->render($inSource),
						$this->render($inDescription),
					);
				}
			}
		}

		return $differences;
	}

	/**
	 * Fields the descriptor states, against what the builder would have taken from the registry instead.
	 * An icon or a color index explicitly set to null is a stated value (TPL-03), so it is compared like
	 * any other. The fields a wrapper is assembled with are left out, see WRAPPER_FIELDS_BUILT_ON_TOP.
	 *
	 * @return array<string, array{mixed, mixed}> field => [value in the source, value in the description]
	 */
	private function collectStatedFields(string $activityType, ActivityDescriptorConfig $descriptor): array
	{
		$builtOnTop = $this->registry->isComplexWrapper($activityType) ? self::WRAPPER_FIELDS_BUILT_ON_TOP : [];

		$fields = [];

		if ($descriptor->nodeType !== null)
		{
			$fields['node_type'] = [
				$descriptor->nodeType->value,
				$this->registry->getNodeType($activityType)->value,
			];
		}
		if ($descriptor->hasIcon())
		{
			$fields['icon'] = [$descriptor->icon, $this->registry->getIcon($activityType)];
		}
		if ($descriptor->hasColorIndex())
		{
			$fields['color_index'] = [$descriptor->colorIndex, $this->registry->getColorIndex($activityType)];
		}
		if ($descriptor->dimensions !== null && !in_array('dimensions', $builtOnTop, true))
		{
			$fields['dimensions'] = [$descriptor->dimensions, $this->registry->getDefaultDimensions($activityType)];
		}
		if ($descriptor->ports !== null && !in_array('ports', $builtOnTop, true))
		{
			$fields['ports'] = [$descriptor->ports, $this->registry->getDefaultPorts($activityType)];
		}

		ksort($fields, SORT_STRING);

		return $fields;
	}

	/**
	 * The order of the keys inside dimensions and ports is not a statement of the descriptor, so a
	 * descriptor that writes height before width does not drift. The order of the ports themselves is
	 * kept: it is how the node lays them out.
	 */
	private function canonicalize(mixed $value): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		$canonical = array_map([$this, 'canonicalize'], $value);
		if (!array_is_list($canonical))
		{
			ksort($canonical, SORT_STRING);
		}

		return $canonical;
	}

	/** Values as written, so that the line can be compared with template.source.json by eye. */
	private function render(mixed $value): string
	{
		return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
