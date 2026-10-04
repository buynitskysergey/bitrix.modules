<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\ActivityDescriptorConfig;

final class ActivityNodeBuilder
{
	/**
	 * The keys of an activity beside the ones the build derives from the source, written where the step states
	 * no tail of its own: an empty document is what every node the build ever wrote carries. A step that does
	 * state a tail states it in full, so the key is written only when the tail names it (TPL-05, '_activity').
	 */
	private const DEFAULT_ACTIVITY_TAIL = ['Document' => null];

	private int $counter = 0;
	private readonly StrictActivityResolver $resolver;

	/**
	 * Names already given to an activity of the current build - the ones this builder generated and the ones
	 * the source states alike. The name of a node is the id the links of the template point at, so two
	 * activities of one name are two activities the canvas cannot tell apart.
	 *
	 * @var array<string, true>
	 */
	private array $usedNames = [];

	/**
	 * Names the source of the current build states, taken out of the pool of generated ones before the first node
	 * is built - see {@see self::reserveNames()}. Kept apart from the names already given out: a name reserved
	 * here is not yet the name of a node, and the node that finally takes it is no node built twice.
	 *
	 * @var array<string, true>
	 */
	private array $reservedNames = [];

	/** @var array<string, int> unresolved activity type => nodes built from registry defaults */
	private array $untrustedActivityCounts = [];

	/** @var array<string, int> 'outer type (inner activity type)' => nodes that lost return properties */
	private array $untrustedInnerActivityCounts = [];

	/** @var array<string, int> unresolved activity type => nodes built from the source descriptor alone */
	private array $descriptorOnlyActivityCounts = [];

	public function __construct(
		private readonly ActivityRegistry $registry,
	)
	{
		$this->resolver = new StrictActivityResolver($registry);
	}

	public function reset(): void
	{
		$this->counter = 0;
		$this->usedNames = [];
		$this->reservedNames = [];
		$this->untrustedActivityCounts = [];
		$this->untrustedInnerActivityCounts = [];
		$this->descriptorOnlyActivityCounts = [];
	}

	/**
	 * Nodes of the last build whose visual part came from registry defaults because the activity does
	 * not resolve here and the source has no descriptor for it. Building them is not an error by itself
	 * - the caller decides whether such a template may be written; see AgentTemplateGenerator.
	 *
	 * Kept apart from {@see self::getUntrustedInnerActivityCounts()} because the two are untrustworthy
	 * for different reasons and only this one has a descriptor as a way out.
	 *
	 * @return array<string, int> activity type => node count
	 */
	public function getUntrustedActivityCounts(): array
	{
		return $this->untrustedActivityCounts;
	}

	/**
	 * Nodes of the last build that lost the return properties of an activity a complex node runs inside
	 * itself: the node was built from the '_inner_type' shorthand, that type does not resolve here, and no
	 * descriptor covers return properties (TPL-03). A node whose 'Rules' the source writes itself takes
	 * nothing from the registry and never lands here.
	 *
	 * The key names the inner type apart from the outer one - that is what tells the reader which module
	 * to install.
	 *
	 * @return array<string, int> 'outer type (inner activity type)' => node count
	 */
	public function getUntrustedInnerActivityCounts(): array
	{
		return $this->untrustedInnerActivityCounts;
	}

	/**
	 * Nodes of the last build whose visual part came from the source descriptor only: the activity does
	 * not resolve here, so there is no activity description to check that descriptor against. Such a node
	 * is faithful to the source and is written, unlike an untrustworthy one - but the environment had no
	 * say in it, which is worth telling the caller about.
	 *
	 * Counted per activity type: an agent may hold dozens of nodes of one type, and the missing module is
	 * the same for all of them.
	 *
	 * @return array<string, int> activity type => node count
	 */
	public function getDescriptorOnlyActivityCounts(): array
	{
		return $this->descriptorOnlyActivityCounts;
	}

	/**
	 * Descriptor fields win over the registry, so that the built node does not depend on which
	 * activity-owning modules are installed.
	 *
	 * @param string|null $innerActivityType type of the activity the node runs inside itself, when the caller
	 *                                       built it from the '_inner_type' shorthand and took its return
	 *                                       properties from the registry
	 * @param array<string, mixed>|null $activityTail keys of the activity the source states in '_activity';
	 *                                                null where it states none, see self::DEFAULT_ACTIVITY_TAIL
	 */
	public function build(
		string $activityType,
		array $properties,
		Position $position,
		?string $id = null,
		?string $nodeTitle = null,
		?ActivityDescriptorConfig $descriptor = null,
		?string $innerActivityType = null,
		?array $activityTail = null,
	): array
	{
		$name = $id ?? $this->generateNextId();
		$this->takeName($name);
		if (!$this->resolver->isNodeTrustworthy($activityType, $descriptor))
		{
			$this->countUntrustedNode($activityType);
		}
		elseif (!$this->registry->hasDescription($activityType))
		{
			// Trustworthy without a description of its own means the descriptor states every field.
			$this->countDescriptorOnlyNode($activityType);
		}
		if ($innerActivityType !== null && !$this->resolver->isInnerActivityTrustworthy($innerActivityType))
		{
			$this->countUntrustedInnerActivity($activityType, $innerActivityType);
		}

		$nodeType = $descriptor?->nodeType ?? $this->registry->getNodeType($activityType);
		$dimensions = $descriptor?->dimensions ?? $this->registry->getDefaultDimensions($activityType);
		$ports = $descriptor?->ports ?? $this->registry->getDefaultPorts($activityType);
		// An icon or color index explicitly set to null in the descriptor stays null, unlike a missing one.
		$icon = $descriptor?->hasIcon() ? $descriptor->icon : $this->registry->getIcon($activityType);
		$colorIndex = $descriptor?->hasColorIndex()
			? $descriptor->colorIndex
			: $this->registry->getColorIndex($activityType);

		$displayTitle = $nodeTitle ?? $properties['Title'] ?? '';

		// The tail stands where 'Document' always stood - between the properties of the activity and its canvas.
		$node = [
			'Name' => $name,
			'Type' => $activityType,
			'Activated' => 'Y',
			'Properties' => empty($properties) ? new \stdClass() : $properties,
		] + ($activityTail ?? self::DEFAULT_ACTIVITY_TAIL);

		$node['Node'] = [
			'id' => $name,
			'type' => $nodeType->value,
			'position' => $position->toArray(),
			'dimensions' => $dimensions,
			'ports' => $ports,
			'node' => [
				'type' => $nodeType->value,
				'title' => $displayTitle,
				'colorIndex' => $colorIndex,
				'frameColorName' => null,
				'frameTextAlign' => null,
				'frameSeparatorPosition' => null,
				'icon' => $icon,
			],
		];

		return $node;
	}

	private function countUntrustedNode(string $activityType): void
	{
		$this->untrustedActivityCounts[$activityType] = ($this->untrustedActivityCounts[$activityType] ?? 0) + 1;
	}

	private function countUntrustedInnerActivity(string $activityType, string $innerType): void
	{
		$subject = "$activityType (inner activity $innerType)";
		$this->untrustedInnerActivityCounts[$subject] = ($this->untrustedInnerActivityCounts[$subject] ?? 0) + 1;
	}

	private function countDescriptorOnlyNode(string $activityType): void
	{
		$this->descriptorOnlyActivityCounts[$activityType] =
			($this->descriptorOnlyActivityCounts[$activityType] ?? 0) + 1
		;
	}

	/**
	 * Takes the names the source states out of the pool of generated ones, before the build creates its first
	 * node. A generated name steps over the names of the nodes already built, and the nodes are built in the
	 * order the document writes them down - so without this a step with no '_id' of its own would be given the
	 * name a step further down states, and that step would then be refused as a node built twice. The document
	 * is allowed to point at a node before declaring it ('_ref' of TPL-05), which makes the position of a
	 * declaration no answer to the question whether its name is free.
	 *
	 * @param list<string> $names see AgentConfig::declaredNodeIds()
	 */
	public function reserveNames(array $names): void
	{
		foreach ($names as $name)
		{
			$this->reservedNames[$name] = true;
		}
	}

	/**
	 * Claims a name for an activity of this build. A name the build already gave out is refused instead of
	 * being written a second time: the links of the template point at a node by its name, so the second node
	 * of that name would take over the wiring of the first one, and nothing downstream would notice - the
	 * comparison of a round trip holds the nodes of two templates against each other by name as well.
	 *
	 * Only a name the source states can arrive here twice: {@see self::generateNextId()} steps over the taken
	 * ones. The caller claims a name of its own the same way - the activity a complex wrapper runs inside
	 * itself is named by the source and written into the template by TemplateBuilder, and it is an activity
	 * of the template like any other.
	 */
	public function takeName(string $name): void
	{
		if (isset($this->usedNames[$name]))
		{
			throw new \InvalidArgumentException(sprintf(
				"Node '%s' is built twice: the source states this name for a node the build already created,"
					. ' and a template names a node once - its links point at it by that name',
				$name,
			));
		}

		$this->usedNames[$name] = true;
	}

	/**
	 * Generates deterministic sequential IDs in A0000_0000_0000_NNNN format, stepping over the names already
	 * spoken for. The shape is no longer this builder's alone: a source reversed from a template keeps a
	 * generated name of the original wherever a step points at that node (TemplateReverser::idIfKept()), and a
	 * node the source names does not move the counter. A number is stepped over only where a node already
	 * carries that name or the source states it somewhere, so a source without such a collision is numbered
	 * exactly as it was before.
	 */
	private function generateNextId(): string
	{
		do
		{
			$this->counter++;

			if ($this->counter > 9999)
			{
				throw new \OverflowException("Too many auto-generated activity IDs (max 9999). Use explicit _id in template.source.json.");
			}

			$name = sprintf('A%04d_%04d_%04d_%04d', 0, 0, 0, $this->counter);
		}
		while (isset($this->usedNames[$name]) || isset($this->reservedNames[$name]));

		return $name;
	}
}
