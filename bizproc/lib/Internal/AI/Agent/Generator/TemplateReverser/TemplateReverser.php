<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

use Bitrix\Bizproc\Activity\Enum\ActivityPortType;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\AgentConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\StepConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityNodeBuilder;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\TemplateBuilder;
use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\ItemType;

/**
 * Reverses a generated template.json back into a template.source.json structure.
 *
 * Mirror of TemplateBuilder. The result is semantically equivalent to the original
 * source, but exact byte equivalence is not guaranteed (key order, omitted defaults).
 */
final class TemplateReverser
{
	private const ROOT_PATH_PROPERTIES = 'Properties';
	private const ROOT_PATH_CHILDREN = 'Children';

	/**
	 * Root sections the source carries as the template holds them: a map of the name to the field of that name.
	 * The constants are not among them - they are a level of the format with keys of its own, see
	 * reverseConstants().
	 */
	private const ROOT_FIELD_SECTIONS = ['PARAMETERS' => 'parameters', 'VARIABLES' => 'variables'];

	/**
	 * The tail a node built from a source without '_activity' carries: mirror of
	 * ActivityNodeBuilder::DEFAULT_ACTIVITY_TAIL, and the one tail this reverse leaves unwritten.
	 */
	private const BUILT_ACTIVITY_TAIL = ['Document' => null];

	private const TRIGGER_FLOW_NAME_MAP = [
		'AiAgentStartTrigger' => 'setup',
		'ScheduledTrigger' => 'scheduled',
		'ImBotNewMessageTrigger' => 'bot_reply',
		'StartWorkTimeTrigger' => 'workday_start',
		'StopWorkTimeTrigger' => 'workday_end',
	];

	/** @var array<string, array> indexed by Name */
	private array $activities = [];

	private LinkGraph $graph;

	private NodeDescriptorExtractor $nodeDescriptors;

	/** @var array<string, true> activity names referenced via {=Name:...} or in conditions */
	private array $referencedIds = [];

	/**
	 * @var array<string, true> nodes already printed by the walk, across all flows of one reverse:
	 *                          meeting such a node again produces a reference step instead of a copy
	 */
	private array $visited = [];

	/** Mirror of TemplateBuilder::$langPrefix — used to detect auto-generated Title values. */
	private string $langPrefix = '';

	public function __construct(
		private readonly ActivityRegistry $registry,
	)
	{
	}

	public function reverse(array $template, string $agentName): array
	{
		$root = $template['TEMPLATE'][0] ?? null;
		if (!is_array($root))
		{
			throw new \InvalidArgumentException('template.TEMPLATE[0] missing');
		}

		$children = $root[self::ROOT_PATH_CHILDREN] ?? [];
		$links = $root[self::ROOT_PATH_PROPERTIES]['Links'] ?? [];

		$this->activities = [];
		foreach ($children as $activity)
		{
			if (isset($activity['Name']))
			{
				$this->activities[$activity['Name']] = $activity;
			}
		}
		$knownNodes = array_fill_keys(array_keys($this->activities), true);
		$this->graph = new LinkGraph($links, $knownNodes);
		$this->nodeDescriptors = new NodeDescriptorExtractor($this->activities);
		$this->referencedIds = $this->collectReferencedIds();
		$this->visited = [];
		$this->langPrefix = strtoupper(str_replace(' ', '_', $agentName)) . '_';

		$wizard = $this->extractWizardMeta();

		$result = [
			'name' => $agentName,
			'title' => $this->unwrapLang($template['NAME'] ?? ''),
			'description' => $this->unwrapLang($template['DESCRIPTION'] ?? ''),
		];

		// The root activity of a template of the designer carries a title of its own, and the build names it
		// after the agent: the key is written only where the two differ, so a source that never needed it
		// stays as it is. An empty title differs from the name of the agent like any other, and the format
		// states it - an empty lang key is written as an empty string on both sides of the round trip.
		$rootTitle = $this->unwrapLang((string)($root[self::ROOT_PATH_PROPERTIES]['Title'] ?? ''));
		if ($rootTitle !== $result['title'])
		{
			$result['root_title'] = $rootTitle;
		}

		if ($wizard?->title !== null)
		{
			$result['wizard_title'] = $wizard->title;
		}
		if ($wizard?->description !== null)
		{
			$result['wizard_description'] = $wizard->description;
		}

		$activityDescriptors = $this->nodeDescriptors->getActivityDescriptors();
		if ($activityDescriptors !== [])
		{
			$result['activity_descriptors'] = $activityDescriptors;
		}

		// Written before the constants, the way the template holds them, and only where the template has
		// something to write: an agent with neither section keeps the source it always had.
		foreach (self::ROOT_FIELD_SECTIONS as $section => $key)
		{
			$fields = $template[$section] ?? [];
			if (is_array($fields) && $fields !== [])
			{
				$result[$key] = $fields;
			}
		}

		$result['constants'] = $this->reverseConstants($template['CONSTANTS'] ?? [], $wizard);
		$this->collectReferenceTargets();
		$result['flows'] = $this->reverseFlows();

		return $result;
	}

	/**
	 * A reference step names the node it points at by its id, and that node may be printed before the
	 * reference appears - an auto-generated id would already have been dropped by then. The flows are
	 * walked once with the result thrown away, just to learn which ids the printing walk has to keep.
	 */
	private function collectReferenceTargets(): void
	{
		$this->reverseFlows();
		$this->visited = [];
	}

	/**
	 * Builds the reversed source back and tells what the round trip changed: the criterion of equivalence
	 * lives in TemplateComparator, and the report of it decides whether such a source may be written.
	 */
	public function verifyRoundTrip(array $originalTemplate, array $reversedSource): EquivalenceReport
	{
		$builder = new TemplateBuilder($this->registry, new ActivityNodeBuilder($this->registry));
		$rebuilt = $builder->build(AgentConfig::fromArray($reversedSource));

		return (new TemplateComparator())->compare($originalTemplate, $rebuilt);
	}

	private function reverseConstants(array $rawConstants, ?WizardMeta $wizard): array
	{
		$constants = [];

		foreach ($rawConstants as $key => $data)
		{
			$entry = [
				'label' => $this->unwrapLang($data['Name'] ?? ''),
				'type' => $data['Type'] ?? 'string',
			];

			$description = $this->unwrapLang((string)($data['Description'] ?? ''));
			if ($description !== '')
			{
				$entry['description'] = $description;
			}

			if (!empty($data['Multiple']))
			{
				$entry['multiple'] = true;
			}
			if (!empty($data['Required']))
			{
				$entry['required'] = true;
			}

			if ($wizard !== null && !isset($wizard->constantKeys[(string)$key]))
			{
				$entry['show_in_wizard'] = false;
			}

			$perConstant = $wizard?->perConstantWizard[(string)$key] ?? null;
			if ($perConstant !== null)
			{
				$entry['wizard_title'] = $perConstant->title;
				if ($perConstant->description !== null)
				{
					$entry['wizard_description'] = $perConstant->description;
				}
			}

			// Options written as a map - an empty one included: a source stating no options at all builds 'Options'
			// as null, so an empty map is a statement of its own and five of the shipped agents carry it.
			if (array_key_exists('Options', $data) && is_array($data['Options']))
			{
				$options = [];
				foreach ($data['Options'] as $value => $labelKey)
				{
					$options[(string)$value] = $this->unwrapLang((string)$labelKey);
				}
				$entry['options'] = $options;
			}

			// An empty default is what a source without the key builds, and null is a value the template does
			// carry - 'sample' states it for a constant of type 'file', so the two are told apart here. A scalar
			// the format cannot state is omitted so the rebuild can reach the equivalence report: it is then named
			// as a difference, refused normally, or written as a marked partial source under --allow-lossy.
			if (
				array_key_exists('Default', $data)
				&& $data['Default'] !== ''
				&& ($data['Default'] === null || is_string($data['Default']) || is_array($data['Default']))
			)
			{
				$entry['default'] = $data['Default'];
			}

			// Settings of the field type are data of the template and no lang keys: they travel as they are, and
			// an empty 'Settings' is written down too - a source without the key builds no such key at all.
			if (array_key_exists('Settings', $data) && is_array($data['Settings']))
			{
				$entry['settings'] = $data['Settings'];
			}

			$constants[(string)$key] = $this->appendWizardLevelValues(
				$entry,
				$wizard?->constantElements[(string)$key] ?? null,
			);
		}

		return $constants;
	}

	/**
	 * Whether the element of the wizard is required and what it is filled with, written down exactly where the
	 * element disagrees with the constant of the same template. The build asks the constant for both at the
	 * wizard level (ConstantConfig::isRequiredInWizard(), ConstantConfig::getDefaultInWizard()), so a constant
	 * the two places agree about keeps the source it always had - the way the tail of an activity does, see
	 * activityTailIfKept().
	 *
	 * @param array<string, mixed> $entry the constant as the source states it
	 *
	 * @return array<string, mixed>
	 */
	private function appendWizardLevelValues(array $entry, ?WizardConstantElement $element): array
	{
		if ($element === null)
		{
			return $entry;
		}

		if ($element->required !== ($entry['required'] ?? false))
		{
			$entry['wizard_required'] = $element->required;
		}

		// Against the value the build would take from the constant: an explicit null of the constant reaches the
		// element as an empty value, so it is the empty value the element is held against.
		if ($element->default !== ($entry['default'] ?? ''))
		{
			$entry['wizard_default'] = $element->default;
		}

		return $entry;
	}

	/**
	 * Extracts wizard metadata from the first SetupTemplateActivity.
	 *
	 * `buildSetupTemplateProperties` emits one base block (global wizardTitle/wizardDescription
	 * + visible constants without per-constant wizard) and starts a new block whenever it hits
	 * a constant with its own wizardTitle. Reverse mirrors that: title/description of the FIRST
	 * block become global wizard meta; title/description of any subsequent block attach to the
	 * FIRST constant of that block (mirrors builder, where only the constant that declared
	 * wizardTitle triggered the new block).
	 *
	 * Returns null when no SetupTemplateActivity is present in the template — callers use this
	 * to distinguish "no wizard at all" from "wizard exists but has no items".
	 */
	private function extractWizardMeta(): ?WizardMeta
	{
		$setup = $this->findActivityByType();
		if ($setup === null)
		{
			return null;
		}
		$blocks = $setup['Properties']['blocks'] ?? null;
		if (!is_array($blocks))
		{
			return null;
		}

		$wizardTitle = null;
		$wizardDescription = null;
		$constantKeys = [];
		$constantElements = [];
		$perConstant = [];
		$isFirstTitledBlock = true;

		foreach ($blocks as $block)
		{
			if (!is_array($block) || empty($block['items']))
			{
				continue;
			}

			$parsed = $this->parseSetupBlock($block);
			foreach ($parsed->constantIds as $cid)
			{
				$constantKeys[$cid] = true;
			}
			// The first declaration of an element wins: the build writes one element per constant, so a second
			// one is a construct of the wizard the format has no way to write down anyway.
			$constantElements += $parsed->constantElements;

			if ($parsed->title === null)
			{
				continue;
			}

			if ($isFirstTitledBlock)
			{
				$wizardTitle = $parsed->title;
				$wizardDescription = $parsed->description;
				$isFirstTitledBlock = false;
				continue;
			}

			// Builder opens a new block only on the constant whose wizardTitle != null.
			// Only that constant owns the title in source; the rest of the block trails
			// from constants without wizardTitle. Reverse must mirror that — otherwise
			// the next forward build splits one block into N (round-trip not idempotent).
			$firstCid = $parsed->constantIds[0] ?? null;
			if ($firstCid !== null)
			{
				$perConstant[$firstCid] = new PerConstantWizard($parsed->title, $parsed->description);
			}
		}

		return new WizardMeta($wizardTitle, $wizardDescription, $constantKeys, $perConstant, $constantElements);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findActivityByType(): ?array
	{
		foreach ($this->activities as $activity)
		{
			if (($activity['Type'] ?? null) === ActivityRegistry::SETUP_ACTIVITY_TYPE)
			{
				return $activity;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private function parseSetupBlock(array $block): ParsedSetupBlock
	{
		$title = null;
		$description = null;
		$constantIds = [];
		$constantElements = [];
		foreach ($block['items'] as $item)
		{
			$itemType = ItemType::tryFrom((string)($item['itemType'] ?? ''));
			if ($itemType === ItemType::Title)
			{
				$title = $this->unwrapLang((string)($item['text'] ?? ''));
			}
			elseif ($itemType === ItemType::Description)
			{
				$description = $this->unwrapLang((string)($item['text'] ?? ''));
			}
			elseif ($itemType === ItemType::Constant && isset($item['id']))
			{
				$id = (string)$item['id'];
				$constantIds[] = $id;
				$constantElements[$id] ??= self::parseWizardElement($item);
			}
		}

		return new ParsedSetupBlock($title, $description, $constantIds, $constantElements);
	}

	/**
	 * What an element of the wizard states about its constant apart from the constant itself. The default is read
	 * as an empty value where the element carries none: the build writes a default for every element it creates,
	 * so an element without one is not something the source can state - and the comparison of the round trip
	 * names it instead of the reverse inventing a key for it.
	 *
	 * @param array<string, mixed> $item element of a block of the wizard
	 */
	private static function parseWizardElement(array $item): WizardConstantElement
	{
		$default = $item['default'] ?? '';

		return new WizardConstantElement(
			required: !empty($item['required']),
			default: is_string($default) || is_array($default) ? $default : '',
		);
	}

	private function reverseFlows(): array
	{
		$flows = [];
		$usedNames = [];

		foreach ($this->activities as $activity)
		{
			if (!$this->registry->isTrigger($activity['Type'] ?? ''))
			{
				continue;
			}

			$flowName = $this->makeFlowName((string)$activity['Type'], $usedNames);
			$flows[$flowName] = $this->buildFlow($activity);
		}

		return $flows;
	}

	private function makeFlowName(string $triggerType, array &$used): string
	{
		$base = self::TRIGGER_FLOW_NAME_MAP[$triggerType] ?? $this->snakeCase($triggerType);
		$name = $base;
		$i = 1;
		while (isset($used[$name]))
		{
			$i++;
			$name = $base . '_' . $i;
		}
		$used[$name] = true;

		return $name;
	}

	private function snakeCase(string $type): string
	{
		$type = preg_replace('/(Trigger|Activity)$/', '', $type) ?? $type;
		$type = preg_replace('/([a-z])([A-Z])/', '$1_$2', $type) ?? $type;

		return strtolower($type);
	}

	private function buildFlow(array $trigger): array
	{
		$flow = [
			'trigger' => (string)$trigger['Type'],
		];

		$name = (string)$trigger['Name'];
		$triggerProps = $this->prependNodeKeys($name, $this->cleanActivityProps($trigger));
		if ($triggerProps !== [])
		{
			$flow['trigger_props'] = $triggerProps;
		}

		$this->visited[$name] = true;
		$targets = $this->graph->allOutgoing($name, 'o0');
		if (count($targets) > 1)
		{
			// Several targets on the trigger output are a flow-level fan-out, and the format gives such
			// a flow no 'steps' at all: the branches are the whole flow.
			$flow['fanout'] = $this->walkPerTarget($targets, null, null);

			return $flow;
		}

		$flow['steps'] = $this->walkChain($name, 'o0', null);

		return $flow;
	}

	/**
	 * Walks the chain leaving $fromName:$fromPort, building step entries until a stop node is reached.
	 *
	 * A port with several targets is a fan-out described by the step that owns the port, so a chain
	 * continues here only while the port has exactly one target.
	 *
	 * @param string|null $stopAt stop when reaching this node (used as merge-point in conditions/branches)
	 * @param string|null $loopbackTarget composite container whose i1 loopback marks "end of body";
	 *                                     when set, also breaks the chain if $cur equals the container itself
	 */
	private function walkChain(
		string $fromName,
		string $fromPort,
		?string $stopAt,
		?string $loopbackTarget = null,
	): array
	{
		$targets = $this->graph->allOutgoing($fromName, $fromPort);

		return count($targets) === 1
			? $this->walkFrom($targets[0]['name'], $stopAt, $loopbackTarget)
			: [];
	}

	/**
	 * Walks one branch: the chain that starts at $startName and ends at the boundary of the branch.
	 *
	 * @param string|null $stopAt see {@see self::walkChain()}
	 * @param string|null $loopbackTarget see {@see self::walkChain()}
	 */
	private function walkFrom(string $startName, ?string $stopAt, ?string $loopbackTarget = null): array
	{
		$steps = [];
		$cur = $startName;

		while ($cur !== null && $cur !== $stopAt)
		{
			if ($loopbackTarget !== null && $cur === $loopbackTarget)
			{
				break; // composite body wraps directly back to its container
			}
			if (isset($this->visited[$cur]))
			{
				$steps[] = $this->buildReferenceStep($cur);
				break;
			}
			if (!isset($this->activities[$cur]))
			{
				break;
			}

			$activity = $this->activities[$cur];
			$type = (string)($activity['Type'] ?? '');
			$this->visited[$cur] = true;

			// The ports of a composite are its body and its exit, never a fan-out: checked before them.
			if ($this->registry->isComposite($type))
			{
				$steps[] = $this->buildCompositeStep($activity);
				$cur = $this->graph->follow($cur, 'o1');
				continue;
			}

			$outPorts = $this->outputPorts($cur);
			if ($this->hasFanoutPort($cur, $outPorts))
			{
				$mergePoint = $this->findCommonMerge($cur, $outPorts);
				$steps[] = $this->buildFanoutStep($activity, $outPorts, $mergePoint, $loopbackTarget);
				$cur = $mergePoint;
				continue;
			}

			if ($type === ActivityRegistry::CONDITION_ACTIVITY_TYPE)
			{
				$mergePoint = $this->findMergePoint($cur);
				$steps[] = $this->buildConditionStep($activity, $mergePoint, $loopbackTarget);
				$cur = $mergePoint;
				continue;
			}

			// Any non-default port topology — multiple ports OR a single non-o0 port — is reified as branches.
			// A complex wrapper always is, an empty branch map included: only a branching key makes the next
			// build keep the 'Rules' of the step with the activity inside them, while a step without one goes
			// the '_inner_id' way and refuses a wrapper the designer wrote.
			$nonStandard = !empty($outPorts) && !in_array('o0', $outPorts, true);
			if (count($outPorts) >= 2 || $nonStandard || $this->registry->isComplexWrapper($type))
			{
				// For ≥2 ports there can be a downstream merge; for a single port the branch absorbs the rest of the chain.
				$mergePoint = count($outPorts) >= 2 ? $this->findCommonMerge($cur, $outPorts) : null;
				$steps[] = $this->buildBranchesStep($activity, $outPorts, $mergePoint, $loopbackTarget);
				$cur = $mergePoint;
				continue;
			}

			$steps[] = $this->buildSimpleStep($activity);

			// In a composite body, the tail step may loop back to the container via o0 → container:i1
			if ($loopbackTarget !== null && $this->loopsBackTo($cur, $loopbackTarget))
			{
				break;
			}
			$cur = $this->graph->follow($cur, 'o0');
		}

		return $steps;
	}

	/**
	 * One branch per edge, in the order the edges appear in Links: the order decides the order of the
	 * links of the next build, and the branch that reaches a node first is the one that prints it.
	 *
	 * @param list<array{name: string, port: string}> $targets
	 *
	 * @return list<array> one entry per target, each a list of step entries
	 */
	private function walkPerTarget(array $targets, ?string $stopAt, ?string $loopbackTarget): array
	{
		$branches = [];
		foreach ($targets as $target)
		{
			$branches[] = $this->walkFrom($target['name'], $stopAt, $loopbackTarget);
		}

		return $branches;
	}

	/**
	 * The outgoing ports of the node that carry control flow: only they become 'branches' or 'fanout',
	 * and only they are followed while looking for the merge point of a step.
	 *
	 * @return list<string>
	 */
	private function outputPorts(string $name): array
	{
		return $this->portsOfType($name, ActivityPortType::Output);
	}

	/**
	 * The outgoing ports of the node that bind nodes to it instead of continuing the flow: what hangs on
	 * them is written in 'attachments'.
	 *
	 * @return list<string>
	 */
	private function auxPorts(string $name): array
	{
		return $this->portsOfType($name, ActivityPortType::Aux);
	}

	/**
	 * Ports of this type the node has an edge on - a port it declares but wires nowhere is not among them.
	 *
	 * @return list<string>
	 */
	private function portsOfType(string $name, ActivityPortType $type): array
	{
		return array_values(array_filter(
			$this->graph->outgoingPorts($name),
			fn(string $port): bool => $this->portType($name, $port) === $type,
		));
	}

	/**
	 * The type of a port comes from the template itself - every port of a node is listed in its Node.ports
	 * with its type - so that the topology of the reverse does not depend on which activity-owning modules
	 * are installed here. The registry answers only for a node the template left without ports at all.
	 *
	 * @return ActivityPortType|null null when the node declares no port with this id
	 */
	private function portType(string $name, string $portId): ?ActivityPortType
	{
		$declaredPorts = $this->activities[$name]['Node']['ports'] ?? null;
		if (!is_array($declaredPorts) || $declaredPorts === [])
		{
			return $this->registryPortType($name, $portId);
		}

		foreach ($declaredPorts as $port)
		{
			if (is_array($port) && ($port['id'] ?? null) === $portId)
			{
				return ActivityPortType::tryFrom((string)($port['type'] ?? ''));
			}
		}

		return null;
	}

	/**
	 * The registry has a predicate per port type rather than a getter, because a port the activity does
	 * not declare belongs to no type - so the type is the one its predicate confirms.
	 */
	private function registryPortType(string $name, string $portId): ?ActivityPortType
	{
		$activityType = (string)($this->activities[$name]['Type'] ?? '');

		foreach (ActivityPortType::cases() as $type)
		{
			if ($this->registry->isPortOfType($activityType, $portId, $type))
			{
				return $type;
			}
		}

		return null;
	}

	/**
	 * Whether any outgoing port of the node leads to more than one node - the topology the source
	 * format describes with 'fanout'.
	 *
	 * @param list<string> $ports
	 */
	private function hasFanoutPort(string $name, array $ports): bool
	{
		foreach ($ports as $port)
		{
			if (count($this->graph->allOutgoing($name, $port)) > 1)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A node another chain already printed: the reference wires this chain into it and closes the branch,
	 * so that a subgraph reachable twice is written once.
	 */
	private function buildReferenceStep(string $name): array
	{
		// The reference carries the node id, so idIfKept() must keep it on the step that declares the node.
		$this->referencedIds[$name] = true;

		return ['_ref' => $name];
	}

	/**
	 * A step at least one of whose ports leads to several branches: 'fanout' takes the place of 'branches'
	 * (and of 'true'/'false' on a condition) and lists the branches of every port.
	 *
	 * @param list<string> $ports
	 */
	private function buildFanoutStep(
		array $activity,
		array $ports,
		?string $mergePoint,
		?string $loopbackTarget,
	): array
	{
		$name = (string)$activity['Name'];
		$type = (string)$activity['Type'];
		$isCondition = $type === ActivityRegistry::CONDITION_ACTIVITY_TYPE;

		$body = $isCondition ? $this->buildConditionBody($activity) : $this->cleanActivityProps($activity);
		$fanout = [];
		foreach ($ports as $port)
		{
			$fanout[$port] = $this->walkPerTarget(
				$this->graph->allOutgoing($name, $port),
				$mergePoint,
				$loopbackTarget,
			);
		}
		$body['fanout'] = $fanout;

		return [($isCondition ? 'Condition' : $type) => $this->buildStepBody($name, $body)];
	}

	/**
	 * Generic merge-point search for a multi-output activity with arbitrary ports.
	 * Returns the closest node reachable from EVERY outgoing edge of those ports and having
	 * inDegree(i0) >= the number of those edges - a fan-out port contributes one edge per branch.
	 *
	 * @param list<string> $ports
	 */
	private function findCommonMerge(string $name, array $ports): ?string
	{
		$reaches = [];
		foreach ($ports as $port)
		{
			foreach ($this->graph->allOutgoing($name, $port) as $target)
			{
				$reaches[] = $this->reachUntilBoundary($target['name']);
			}
		}

		$common = null;
		foreach ($reaches as $r)
		{
			$common = $common === null ? $r : array_intersect_key($common, $r);
			if (empty($common))
			{
				return null;
			}
		}
		if ($common === null)
		{
			return null;
		}

		$minInDegree = count($reaches);
		$merge = null;
		$bestDist = PHP_INT_MAX;

		foreach (array_keys($common) as $node)
		{
			if ($this->graph->inDegree($node, 'i0') < $minInDegree)
			{
				continue;
			}
			$dist = 0;
			foreach ($reaches as $r)
			{
				$dist += $r[$node];
			}
			if ($dist < $bestDist)
			{
				$bestDist = $dist;
				$merge = $node;
			}
		}

		return $merge;
	}

	/**
	 * @param list<string> $ports
	 */
	private function buildBranchesStep(
		array $activity,
		array $ports,
		?string $mergePoint,
		?string $loopbackTarget,
	): array
	{
		$type = (string)$activity['Type'];
		$name = (string)$activity['Name'];
		$props = $this->cleanActivityProps($activity);

		$branches = [];
		foreach ($ports as $port)
		{
			$branches[$port] = $this->walkChain($name, $port, $mergePoint, $loopbackTarget);
		}

		$body = $props;
		$body['branches'] = $branches;

		return [$type => $this->buildStepBody($name, $body)];
	}

	private function findMergePoint(string $condName): ?string
	{
		$trueStart = $this->graph->follow($condName, 'o0');
		$falseStart = $this->graph->follow($condName, 'o1');

		// Bounded BFS: stop traversal through nodes with inDegree >= 2 (potential merge boundaries).
		// This prevents nested conditions' merge-points from being misidentified as the parent's merge.
		$trueReach = $trueStart !== null ? $this->reachUntilBoundary($trueStart) : [];
		$falseReach = $falseStart !== null ? $this->reachUntilBoundary($falseStart) : [];

		$merge = null;
		$bestDist = PHP_INT_MAX;

		foreach ($trueReach as $node => $dT)
		{
			if (!isset($falseReach[$node]))
			{
				continue;
			}
			$inDeg = $this->graph->inDegree($node, 'i0');
			if ($inDeg < 2)
			{
				continue;
			}
			$dist = $dT + $falseReach[$node];
			if ($dist < $bestDist)
			{
				$bestDist = $dist;
				$merge = $node;
			}
		}

		return $merge;
	}

	/**
	 * BFS from $startName that does not traverse THROUGH nodes whose inDegree(i0) >= 2.
	 * Boundary nodes themselves are included with their distance; their descendants are not.
	 *
	 * @return array<string, int>
	 */
	private function reachUntilBoundary(string $startName): array
	{
		$distances = [];
		$queue = [[$startName, 0]];
		$head = 0;

		while ($head < count($queue))
		{
			[$current, $dist] = $queue[$head++];
			if (isset($distances[$current]))
			{
				continue;
			}
			$distances[$current] = $dist;

			// Boundary: do not expand THROUGH inDegree>=2 nodes (except the entry point itself).
			if ($current !== $startName && $this->graph->inDegree($current, 'i0') >= 2)
			{
				continue;
			}

			// Use the node's actual output ports so multi-branch activities (>=3 ports) don't get
			// truncated to o0/o1 - findCommonMerge depends on full reachability of the control flow.
			foreach ($this->outputPorts($current) as $outPort)
			{
				foreach ($this->graph->allOutgoing($current, $outPort) as $next)
				{
					if ($next['port'] === 'i1' && $next['name'] === $current)
					{
						continue;
					}
					if (!isset($distances[$next['name']]))
					{
						$queue[] = [$next['name'], $dist + 1];
					}
				}
			}
		}

		return $distances;
	}

	private function buildConditionStep(array $activity, ?string $mergePoint, ?string $loopbackTarget): array
	{
		$name = (string)$activity['Name'];
		$body = $this->buildConditionBody($activity) + [
				'true' => $this->walkChain($name, 'o0', $mergePoint, $loopbackTarget),
				'false' => $this->walkChain($name, 'o1', $mergePoint, $loopbackTarget),
			];

		return ['Condition' => $this->buildStepBody($name, $body)];
	}

	/**
	 * Everything of a condition step except the wiring of its output ports: the ports lead either to one
	 * branch each ('true'/'false') or to several ('fanout').
	 */
	private function buildConditionBody(array $activity): array
	{
		$conditions = [];
		$rawConditions = $activity['Properties']['mixedcondition'] ?? [];
		if (!is_array($rawConditions))
		{
			$rawConditions = [];
		}
		foreach ($rawConditions as $c)
		{
			$entry = [
				'object' => $c['object'] ?? '',
				'field' => $c['field'] ?? '',
				'operator' => $c['operator'] ?? '',
				'value' => $c['value'] ?? '',
			];
			$joiner = $c['joiner'] ?? 0;
			if ($joiner)
			{
				$entry['joiner'] = (int)$joiner;
			}
			$conditions[] = $entry;
		}

		// Preserve user-set Title (and any other custom props) — cleanActivityProps drops
		// only the auto-generated title pattern and engine-managed fields.
		$props = $this->cleanActivityProps($activity);
		unset($props['mixedcondition']);

		return $props + ['conditions' => $conditions];
	}

	private function buildCompositeStep(array $activity): array
	{
		$type = (string)$activity['Type'];
		$configType = $type === ActivityRegistry::FOREACH_ACTIVITY_TYPE ? 'ForEach' : $type;

		$props = $this->cleanActivityProps($activity);

		// ForEach reverse: collapse Object + Variable back into Variable: "{=Object:Variable}"
		if (
			$type === ActivityRegistry::FOREACH_ACTIVITY_TYPE
			&& isset($props['Object'])
			&& isset($props['Variable'])
		)
		{
			$props['Variable'] = '{=' . $props['Object'] . ':' . $props['Variable'] . '}';
			unset($props['Object']);
		}

		// Walk the composite body: starts at composite:o0, ends either when a tail wraps back
		// to composite:i1 or when we step directly back into composite itself (empty body case).
		$childSteps = $this->walkChain($activity['Name'], 'o0', null, $activity['Name']);

		$body = $props;
		$body['steps'] = $childSteps;

		return [$configType => $this->buildStepBody((string)$activity['Name'], $body)];
	}

	private function loopsBackTo(string $name, string $composite): bool
	{
		foreach ($this->graph->allOutgoing($name, 'o0') as $target)
		{
			if ($target['name'] === $composite && $target['port'] === 'i1')
			{
				return true;
			}
		}

		return false;
	}

	private function buildSimpleStep(array $activity): string|array
	{
		// cleanActivityProps already strips engine-rebuilt fields (user/blocks for setup,
		// auto-generated Title) and keeps custom values (e.g. user-authored Title).
		return self::asStep(
			(string)$activity['Type'],
			$this->buildStepBody((string)$activity['Name'], $this->cleanActivityProps($activity)),
		);
	}

	/**
	 * A node bound to an aux port of another one: the step a chain would print, minus everything that
	 * continues - a binding wires nothing further, and TPL-04 gives it no bindings of its own.
	 */
	private function buildBoundNodeStep(array $activity): string|array
	{
		return self::asStep(
			(string)$activity['Type'],
			$this->prependNodeKeys((string)$activity['Name'], $this->cleanActivityProps($activity)),
		);
	}

	/**
	 * A step with no construct of its own is written as the activity type alone while its body is empty.
	 *
	 * @param array<string, mixed> $body
	 */
	private static function asStep(string $type, array $body): string|array
	{
		return $body === [] ? $type : [$type => $body];
	}

	/**
	 * Body of a step of the walk: the keys of the node itself, the nodes bound to its aux ports and then
	 * what the construct of the step built. The bindings are printed before the branches on purpose - they
	 * belong to the node, and a reader would lose them after a long list of branches. A trigger has no aux
	 * ports and no step body: the flow prepends its node keys directly.
	 *
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 */
	private function buildStepBody(string $name, array $body): array
	{
		$attachments = $this->collectAttachments($name);

		return $this->prependNodeKeys(
			$name,
			$attachments === [] ? $body : ['attachments' => $attachments] + $body,
		);
	}

	/**
	 * Nodes bound to the aux ports of a node - a knowledge base, a set of tools the activity uses - and
	 * no part of the control flow. Each of them is printed here and marked as printed: it is reachable
	 * through an aux port alone, and it continues nothing, so the walk never comes back to it.
	 *
	 * @return array<string, list<string|array>> aux port id => bound nodes, in the order of the links
	 */
	private function collectAttachments(string $name): array
	{
		$attachments = [];

		foreach ($this->auxPorts($name) as $port)
		{
			foreach ($this->graph->allOutgoing($name, $port) as $edge)
			{
				$bound = $edge['name'];
				// A build wires a binding into the top aux port of the bound node, so an edge entering
				// that node anywhere else is not a binding this format can express.
				if ($this->portType($bound, $edge['port']) !== ActivityPortType::TopAux)
				{
					continue;
				}
				// One node bound to two owners is printed once: the format declares a bound node where it
				// hangs, and a second declaration of the same id would build a second node.
				if (isset($this->visited[$bound]) || !isset($this->activities[$bound]))
				{
					continue;
				}

				$this->visited[$bound] = true;
				$attachments[$port][] = $this->buildBoundNodeStep($this->activities[$bound]);
			}
		}

		return $attachments;
	}

	/**
	 * Prepends the node-level keys of the source format to a step body: '_id' when the generated name
	 * has to be kept, '_node' when the node deviates from the descriptor of its activity type, '_activity'
	 * when the activity carries something else than the build writes by itself.
	 *
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 */
	private function prependNodeKeys(string $name, array $body): array
	{
		$nodeKeys = [];

		$id = $this->idIfKept($name);
		if ($id !== null)
		{
			$nodeKeys['_id'] = $id;
		}

		$deviation = $this->nodeDescriptors->getNodeDeviation($name);
		if ($deviation !== null)
		{
			$nodeKeys['_node'] = $deviation;
		}

		$activityTail = $this->activityTailIfKept($name);
		if ($activityTail !== null)
		{
			$nodeKeys[StepConfig::ACTIVITY_KEY] = $activityTail;
		}

		return $nodeKeys + $body;
	}

	/**
	 * The keys of the activity the format states nowhere else - 'Document' and whatever the designer wrote
	 * beside the activity, a preset id of a trigger for one. Written down only where the activity carries
	 * something else than the build writes by itself: a node with the usual empty document says nothing here,
	 * so the sources written before this key stay as they are.
	 *
	 * A node without a 'Document' key is such a difference too, and it is written as an empty tail: the build
	 * writes the key for every node it creates, and the shipped bytes of seven agents say otherwise.
	 *
	 * @return array<string, mixed>|null null when the tail is the one the build writes anyway
	 */
	private function activityTailIfKept(string $name): ?array
	{
		$activity = $this->activities[$name] ?? null;
		if (!is_array($activity))
		{
			return null;
		}

		$tail = array_diff_key($activity, array_flip(StepConfig::ACTIVITY_KEYS_EXPRESSED_OTHERWISE));

		return $tail === self::BUILT_ACTIVITY_TAIL ? null : $tail;
	}

	private function cleanActivityProps(array $activity): array
	{
		$props = $activity['Properties'] ?? [];
		if (!is_array($props))
		{
			return [];
		}

		// The canvas title of the node is compared against the property the node was built with, so it is
		// read before the auto-generated Title is dropped below - otherwise every such node gets NodeTitle.
		$builtTitle = $props['Title'] ?? null;

		// Title is rebuilt by the generator from langPrefix iff the user did not specify one.
		// Drop only the auto-generated pattern ###<LANG_PREFIX><TYPE>_TITLE###; keep custom titles.
		if (
			isset($props['Title'])
			&& $this->isAutoGeneratedTitle(
				(string)$props['Title'],
				(string)($activity['Type'] ?? ''),
			)
		)
		{
			unset($props['Title']);
		}

		// Empty UI-only fields (e.g. blank designer comments) just clutter the source.
		if (($props['EditorComment'] ?? null) === '')
		{
			unset($props['EditorComment']);
		}

		if (($activity['Type'] ?? '') === ActivityRegistry::SETUP_ACTIVITY_TYPE)
		{
			// user + blocks are rebuilt by buildSetupTemplateProperties from constants + wizard meta.
			unset($props['user'], $props['blocks']);
		}

		// Written wherever the canvas title differs from the one the build would put there - the node title of the
		// source, the Title of the activity where the source states none (ActivityNodeBuilder). An empty title
		// differs like any other and the format states it: a node with an empty canvas title used to reach no key
		// at all, the build then wrote the Title of the activity onto the canvas, the comparison called it drift,
		// and the reverse refused a template it can describe - the way it refused an empty 'root_title' before.
		$canvasTitle = $activity['Node']['node']['title'] ?? null;
		if (is_string($canvasTitle) && $canvasTitle !== $builtTitle)
		{
			$props['NodeTitle'] = $canvasTitle;
		}

		return $props;
	}

	private function idIfKept(string $name): ?string
	{
		if ($this->isAutoGeneratedId($name))
		{
			if (!isset($this->referencedIds[$name]))
			{
				return null;
			}
		}

		return $name;
	}

	private function isAutoGeneratedId(string $name): bool
	{
		return (bool)preg_match('/^A0{4}_0{4}_0{4}_\d{4}$/', $name);
	}

	private function isAutoGeneratedTitle(string $title, string $activityType): bool
	{
		if ($activityType === '')
		{
			return false;
		}

		$expected = '###' . $this->langPrefix . strtoupper($activityType) . '_TITLE###';

		return $title === $expected;
	}

	/** @return array<string, true> */
	private function collectReferencedIds(): array
	{
		$refs = [];

		$walker = function($value) use (&$walker, &$refs): void {
			$inlinePattern = '/\{=(' . StepConfig::ID_PATTERN_BODY . '):/';
			if (is_string($value))
			{
				if (preg_match_all($inlinePattern, $value, $m))
				{
					foreach ($m[1] as $id)
					{
						$refs[$id] = true;
					}
				}

				return;
			}
			if (is_array($value))
			{
				if (
					isset($value['object']) && is_string($value['object'])
					&& preg_match(
						StepConfig::ID_PATTERN,
						$value['object'],
					)
				)
				{
					$refs[$value['object']] = true;
				}
				foreach ($value as $v)
				{
					$walker($v);
				}
			}
		};

		foreach ($this->activities as $activity)
		{
			$walker($activity['Properties'] ?? []);
		}

		return $refs;
	}

	private function unwrapLang(string $value): string
	{
		if (preg_match('/^###(.+)###$/', $value, $m))
		{
			return $m[1];
		}

		return $value;
	}
}
