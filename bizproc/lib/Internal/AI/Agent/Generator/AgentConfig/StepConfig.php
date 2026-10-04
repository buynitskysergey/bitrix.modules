<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

use Bitrix\Bizproc\Activity\Enum\ActivityPortType;

final class StepConfig
{
	/** Body of the activity-ID regex without delimiters or anchors — share it via {@see self::ID_PATTERN_BODY}. */
	public const ID_PATTERN_BODY = 'A\d{4,}_\d{4,}_\d{4,}_\d{4,}';
	public const ID_PATTERN = '/^' . self::ID_PATTERN_BODY . '$/';

	/**
	 * Keys the step body reserves for itself. Every other key is an activity property and reaches
	 * Properties as written: properties of some activities are dynamic, so the step level has no
	 * closed list of its own. Together with {@see self::REF_KEY} these are the keys TPL-05 reserves.
	 */
	private const RESERVED_KEYS = [
		'_id',
		'_node',
		'_activity',
		'_inner_id',
		'_inner_type',
		'conditions',
		'true',
		'false',
		'steps',
		'branches',
		'fanout',
		'attachments',
	];

	/** The key of a step body carrying the tail of the activity - see {@see self::parseActivityTail()}. */
	public const ACTIVITY_KEY = '_activity';

	/**
	 * Keys of an activity the format states in a way of its own: the type of the step names the activity, '_id'
	 * names it inside the template, the body of the step carries its properties, '_node' its canvas, and the
	 * children of a complex wrapper come from the construct that wires it. None of them may stand in
	 * '_activity' - one key with two ways to write it is two answers to one question.
	 *
	 * Shared with the reverse and with the comparison of a round trip: all three ask the same question about an
	 * activity - what of it the format states elsewhere - and an answer kept in three places would drift apart.
	 */
	public const ACTIVITY_KEYS_EXPRESSED_OTHERWISE = ['Name', 'Type', 'Activated', 'Properties', 'Node', 'Children'];

	/** A whole step of its own - see {@see self::refFromArray()} - never a key of a step body. */
	private const REF_KEY = '_ref';

	/**
	 * Keys a step body is wired by: the branches of a condition, the body of a composite step, the
	 * branches of its output ports. 'attachments' is none of them - a binding hangs on an aux port and
	 * wires nothing (TPL-04), so it joins whichever construct the body carries.
	 */
	private const CONSTRUCT_KEYS = ['conditions', 'true', 'false', 'steps', 'branches', 'fanout'];

	/**
	 * The wiring constructs of a step, each as the keys it is written with: a condition and the branches of
	 * its ports, a condition whose ports lead to several branches each, one branch per output port, a
	 * composite body. A body carries one construct, so its keys have to be a subset of one of these sets -
	 * which also leaves 'fanout' on its own allowed, as the second set without the condition.
	 */
	private const CONSTRUCT_KEY_SETS = [
		['conditions', 'true', 'false'],
		['conditions', 'fanout'],
		['branches'],
		['steps'],
	];

	/** Where the ports of a condition lead. A part of that construct, never a construct of its own. */
	private const CONDITION_BRANCH_KEYS = ['true', 'false'];

	/**
	 * Keys of a step body that continue the flow. A node bound to an aux port continues nothing, so
	 * none of them may appear in a binding.
	 */
	private const CONTINUATION_KEYS = ['steps', 'branches', 'fanout', 'conditions', 'true', 'false', 'attachments'];

	/**
	 * Keys that name the activity running inside a complex wrapper. A bound node is built as the node it
	 * names and carries no activity inside it, so neither of them may appear in a binding either.
	 */
	private const INNER_ACTIVITY_KEYS = ['_inner_id', '_inner_type'];

	public function __construct(
		public readonly string $type,
		public readonly array $props = [],
		public readonly ?string $id = null,
		public readonly ?string $innerId = null,
		public readonly ?string $innerType = null,
		public readonly bool $isCondition = false,
		/** @var ConditionConfig[] */
		public readonly array $conditions = [],
		/** @var StepConfig[] */
		public readonly array $trueBranch = [],
		/** @var StepConfig[] */
		public readonly array $falseBranch = [],
		public readonly bool $isComposite = false,
		/** @var StepConfig[] */
		public readonly array $childSteps = [],
		public readonly bool $isBranches = false,
		/** @var array<string, StepConfig[]> port id ("o0", "o1", ...) → branch steps */
		public readonly array $branches = [],
		public readonly ?ActivityDescriptorConfig $nodeDescriptor = null,
		/**
		 * Keys of the activity beside the ones the format states otherwise, as the step writes them down in
		 * '_activity'; null where the step states none and the build writes the tail it always wrote.
		 *
		 * @var array<string, mixed>|null
		 */
		public readonly ?array $activityTail = null,
		public readonly bool $isFanout = false,
		/** @var array<string, StepConfig[][]> port id => branches leaving the port, order significant */
		public readonly array $fanout = [],
		/** ID of an already declared node this step is a reference to, instead of a node of its own. */
		public readonly ?string $refId = null,
		/** @var array<string, StepConfig[]> aux port id ("a0", ...) => nodes bound to it, order significant */
		public readonly array $attachments = [],
	) {}

	/**
	 * Whether the step writes the shorthand of a complex wrapper: the activity the node runs inside itself,
	 * named by '_inner_id' and '_inner_type' instead of being written out in 'Rules'.
	 *
	 * Asked of the step and never of the registry of activities: the registry answers that a type is no complex
	 * wrapper whenever the module owning it is not deployed here, and the node would then be built as a plain
	 * one - without 'Rules' and without 'Children' - losing the activity inside it without a word.
	 */
	public function hasInnerActivityShorthand(): bool
	{
		return $this->innerId !== null || $this->innerType !== null;
	}

	/**
	 * Every node name this step states, the steps written inside it included: the name of its own node and the
	 * name of the activity a complex wrapper runs inside it - named by '_inner_id' where the step writes the
	 * shorthand, and by the rules themselves where the step writes them out. A reference states none - it points
	 * at a node another step declares - so it adds nothing here.
	 *
	 * @return list<string>
	 */
	public function declaredNodeIds(): array
	{
		$ids = [];

		foreach ([$this->id, $this->innerId] as $id)
		{
			if ($id !== null)
			{
				$ids[] = $id;
			}
		}

		// Rules written out are read for the names alone: whether this step is built as a wrapper at all is
		// answered by the step and by the environment, while a name the rules state is a name of the document
		// either way - and a reservation that ends up naming no node costs nothing, it only keeps the counter
		// of generated names off that one.
		$rules = $this->props[ComplexActivityRules::PROPERTY] ?? null;
		array_push($ids, ...ComplexActivityRules::statedActivityNames($rules));

		foreach ($this->nestedSteps() as $nested)
		{
			array_push($ids, ...$nested->declaredNodeIds());
		}

		return $ids;
	}

	/**
	 * The steps written inside this one, whichever construct carries them - and the nodes its bindings declare,
	 * which are steps of the format too (TPL-04).
	 *
	 * @return list<self>
	 */
	private function nestedSteps(): array
	{
		$stepLists = [$this->trueBranch, $this->falseBranch, $this->childSteps];

		foreach ($this->branches as $branchSteps)
		{
			$stepLists[] = $branchSteps;
		}
		foreach ($this->fanout as $branches)
		{
			foreach ($branches as $branchSteps)
			{
				$stepLists[] = $branchSteps;
			}
		}
		foreach ($this->attachments as $boundSteps)
		{
			$stepLists[] = $boundSteps;
		}

		return array_merge(...$stepLists);
	}

	public static function fromMixed(mixed $data): self
	{
		if (is_string($data))
		{
			return new self(type: $data);
		}

		if (!is_array($data) || empty($data))
		{
			throw new \InvalidArgumentException('Step definition must be a non-empty string or array');
		}

		$type = array_key_first($data);
		if (!is_string($type))
		{
			throw new \InvalidArgumentException('Step array must have a string key as activity type');
		}

		if (array_key_exists(self::REF_KEY, $data))
		{
			return self::refFromArray($data);
		}

		$config = is_array($data[$type]) ? $data[$type] : [];
		if (array_key_exists(self::REF_KEY, $config))
		{
			throw new \InvalidArgumentException(sprintf(
				"Step '%s': '%s' is a step of its own - it stands where an activity type stands, not inside a step body",
				$type,
				self::REF_KEY,
			));
		}

		$id = array_key_exists('_id', $config) ? self::validateId($config['_id']) : null;
		$nodeDescriptor = array_key_exists('_node', $config)
			? ActivityDescriptorConfig::fromMixed("Step '$type' _node", $config['_node'])
			: null;
		$activityTail = array_key_exists(self::ACTIVITY_KEY, $config)
			? self::parseActivityTail("Step '$type'", $config[self::ACTIVITY_KEY])
			: null;
		$props = array_diff_key($config, array_fill_keys(self::RESERVED_KEYS, true));

		self::assertConstructIsAlone($type, $config);
		self::assertBranchesHaveTheirCondition($type, $config);
		self::assertInnerActivityShorthandCarriesNoConstruct($type, $config);

		$isFanout = array_key_exists('fanout', $config);
		$fanout = $isFanout ? self::parseFanoutMap($type, $config['fanout']) : [];
		// Bindings hang on the aux ports of the node and wire nothing further, so they live next to
		// every construct that wires its output ports instead of replacing one of them.
		$attachments = array_key_exists('attachments', $config)
			? self::parseAttachmentMap($type, $config['attachments'])
			: [];

		// Condition: has 'conditions' + 'true'/'false' branches, or 'fanout' instead of them
		if (array_key_exists('conditions', $config))
		{
			$rawConditions = $config['conditions'];
			if (!is_array($rawConditions))
			{
				throw new \InvalidArgumentException(sprintf(
					"Step '%s': 'conditions' must be an array of conditions, got %s",
					$type,
					get_debug_type($rawConditions),
				));
			}
			$trueRaw = self::conditionBranch($type, $config, 'true');
			$falseRaw = self::conditionBranch($type, $config, 'false');

			return new self(
				type: $type,
				props: $props,
				id: $id,
				isCondition: true,
				conditions: array_map([ConditionConfig::class, 'fromArray'], $rawConditions),
				trueBranch: array_map([self::class, 'fromMixed'], $trueRaw),
				falseBranch: array_map([self::class, 'fromMixed'], $falseRaw),
				nodeDescriptor: $nodeDescriptor,
				activityTail: $activityTail,
				isFanout: $isFanout,
				fanout: $fanout,
				attachments: $attachments,
			);
		}

		// Fan-out: an output port of the activity leads to several branches
		if ($isFanout)
		{
			return new self(
				type: $type,
				props: $props,
				id: $id,
				nodeDescriptor: $nodeDescriptor,
				activityTail: $activityTail,
				isFanout: true,
				fanout: $fanout,
				attachments: $attachments,
			);
		}

		if (array_key_exists('branches', $config))
		{
			return new self(
				type: $type,
				props: $props,
				id: $id,
				isBranches: true,
				branches: self::parseBranchMap($type, $config['branches']),
				nodeDescriptor: $nodeDescriptor,
				activityTail: $activityTail,
				attachments: $attachments,
			);
		}

		// Composite: has 'steps' (ForEach, etc.)
		if (array_key_exists('steps', $config))
		{
			$rawSteps = $config['steps'];
			if (!is_array($rawSteps))
			{
				throw new \InvalidArgumentException(sprintf(
					"Step '%s': 'steps' must be an array of steps, got %s",
					$type,
					get_debug_type($rawSteps),
				));
			}
			$childSteps = array_map([self::class, 'fromMixed'], $rawSteps);

			if (isset($props['Variable']) && is_string($props['Variable']))
			{
				if (preg_match('/^\{=(\w+):(\w+)}$/', $props['Variable'], $matches))
				{
					$props['Object'] = $matches[1];
					$props['Variable'] = $matches[2];
				}
			}

			return new self(
				type: $type,
				props: $props,
				id: $id,
				isComposite: true,
				childSteps: $childSteps,
				nodeDescriptor: $nodeDescriptor,
				activityTail: $activityTail,
				attachments: $attachments,
			);
		}

		// Simple activity or complex wrapper — detected by type in TemplateBuilder
		$innerId = array_key_exists('_inner_id', $config)
			? self::validateId($config['_inner_id'], '_inner_id')
			: null;
		$innerType = array_key_exists('_inner_type', $config)
			? self::validateInnerType($type, $config['_inner_type'])
			: null;

		return new self(
			type: $type,
			props: $props,
			id: $id,
			innerId: $innerId,
			innerType: $innerType,
			nodeDescriptor: $nodeDescriptor,
			activityTail: $activityTail,
			attachments: $attachments,
		);
	}

	/**
	 * The tail of an activity: the keys of it the format has no way of its own to state - 'Document' and
	 * whatever else the designer wrote beside the activity, a preset id for one. A step stating the tail
	 * states it in full: the build writes exactly these keys and nothing more, so an empty map is a node
	 * with no tail at all, while a step stating no tail keeps the tail the build has always written.
	 *
	 * A tail written as a list is refused the way one written as a string is: the build would join it with the
	 * activity and write keys like '"0": "Preset"' into the node. Only a non-empty list is refused - an empty
	 * JSON object and an empty JSON list are one and the same value after decoding, and an empty tail is the
	 * form a node with no tail at all is written as.
	 *
	 * @param string $context step or flow the tail belongs to, as the refusal names it
	 *
	 * @return array<string, mixed>
	 */
	public static function parseActivityTail(string $context, mixed $raw): array
	{
		if (!is_array($raw) || ($raw !== [] && array_is_list($raw)))
		{
			throw new \InvalidArgumentException(sprintf(
				"%s: '%s' must be a map of the keys of the activity the format states nowhere else, got %s",
				$context,
				self::ACTIVITY_KEY,
				is_array($raw) ? 'a list' : get_debug_type($raw),
			));
		}

		$statedTwice = array_values(array_intersect(self::ACTIVITY_KEYS_EXPRESSED_OTHERWISE, array_keys($raw)));
		if (!empty($statedTwice))
		{
			throw new \InvalidArgumentException(sprintf(
				"%s: '%s' cannot carry [%s] - the format states each of them in a way of its own (the type of the"
					. " step and its '_id', the properties in the body of the step, the canvas in '_node', the"
					. ' activity of a complex wrapper in the construct that wires it)',
				$context,
				self::ACTIVITY_KEY,
				implode(', ', $statedTwice),
			));
		}

		return $raw;
	}

	/**
	 * A reference wires the current continuation into an already declared node instead of creating one.
	 * It stands in the position of an activity type, so it is recognized before the body of a step is
	 * parsed: the closed lists of TPL-05 check a body, and '{"_ref": "A..."}' has none.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function refFromArray(array $data): self
	{
		$otherKeys = array_diff(array_keys($data), [self::REF_KEY]);
		if (!empty($otherKeys))
		{
			throw new \InvalidArgumentException(sprintf(
				"Step '%s' must be the only key of a reference step, got also [%s]",
				self::REF_KEY,
				implode(', ', $otherKeys),
			));
		}

		$refId = $data[self::REF_KEY];
		if (!is_string($refId))
		{
			throw new \InvalidArgumentException(sprintf(
				"Step '%s' must be the ID of an already declared node, got %s",
				self::REF_KEY,
				get_debug_type($refId),
			));
		}

		return new self(type: self::REF_KEY, refId: self::validateId($refId));
	}

	/**
	 * A step body is wired by one construct: it says where a single continuation goes, or where each of
	 * several go, or what runs inside the step - and two of them in one body mean one was written for
	 * nothing. The parse looks at the constructs in an order of its own, so without this the body would be
	 * accepted and everything under the construct it looks at second would be gone without a word.
	 *
	 * @param array<string, mixed> $config
	 */
	private static function assertConstructIsAlone(string $type, array $config): void
	{
		$present = array_intersect(array_keys($config), self::CONSTRUCT_KEYS);
		if ($present === [])
		{
			return;
		}

		foreach (self::CONSTRUCT_KEY_SETS as $construct)
		{
			if (array_diff($present, $construct) === [])
			{
				return;
			}
		}

		throw new \InvalidArgumentException(sprintf(
			"Step '%s': [%s] are mutually exclusive - a step is wired by one construct, and the steps under"
				. ' the others would be dropped',
			$type,
			implode(', ', $present),
		));
	}

	/**
	 * A branch of a condition is a part of that construct and not one of its own: a body carrying a branch
	 * without 'conditions' is no condition, so the parse would read it as a plain activity - and the branch,
	 * a reserved key, would be dropped from the properties too, taking every step under it silently.
	 *
	 * @param array<string, mixed> $config
	 */
	private static function assertBranchesHaveTheirCondition(string $type, array $config): void
	{
		$branches = array_values(array_intersect(self::CONDITION_BRANCH_KEYS, array_keys($config)));
		if ($branches === [] || array_key_exists('conditions', $config))
		{
			return;
		}

		throw new \InvalidArgumentException(sprintf(
			"Step '%s': [%s] are the branches of a condition and mean nothing without 'conditions' - state the"
				. ' condition the branches belong to, or drop the branch instead of leaving its steps to be'
				. ' dropped by the parse',
			$type,
			implode(', ', $branches),
		));
	}

	/**
	 * The shorthand asks the build to create the Rules of a sequential complex wrapper from the one activity it
	 * names, and a body carrying a construct is built as that construct instead - a condition, a fan-out, one
	 * branch per port, a composite body - and none of them looks at the shorthand. Its keys are reserved, so they
	 * are out of the properties by then too: the wrapper would be written without the activity it declares - the
	 * very loss the build refuses one step later for a wrapper this environment cannot confirm as one. A wrapper
	 * wired by a construct states its Rules itself, the way a reversed source writes every wrapper it prints.
	 *
	 * @param array<string, mixed> $config
	 */
	private static function assertInnerActivityShorthandCarriesNoConstruct(string $type, array $config): void
	{
		$innerKeys = array_values(array_intersect(self::INNER_ACTIVITY_KEYS, array_keys($config)));
		$constructKeys = array_values(array_intersect(self::CONSTRUCT_KEYS, array_keys($config)));
		if ($innerKeys === [] || $constructKeys === [])
		{
			return;
		}

		throw new \InvalidArgumentException(sprintf(
			"Step '%s': ['%s'] cannot be combined with [%s]: the shorthand builds the Rules of a sequential"
				. ' complex wrapper from the activity it names, while a body wired by a construct is built as that'
				. ' construct and never reads the shorthand - the wrapper would carry no activity inside it.'
				. " A wrapper wired by a construct states its 'Rules' itself",
			$type,
			implode("', '", $innerKeys),
			implode(', ', $constructKeys),
		));
	}

	/**
	 * A branch of a condition, as the body of the step states it: no key at all is a branch with no steps in it,
	 * so an explicit null is a value here and refused with the rest. It used to reach '??' and become the empty
	 * branch, and the steps the author did write under the key were gone without a word.
	 *
	 * @param array<string, mixed> $config
	 * @param string $branch key of the branch, one of {@see self::CONDITION_BRANCH_KEYS}
	 *
	 * @return array<mixed> steps of the branch, as the body writes them
	 */
	private static function conditionBranch(string $type, array $config, string $branch): array
	{
		$raw = array_key_exists($branch, $config) ? $config[$branch] : [];
		if (!is_array($raw))
		{
			throw new \InvalidArgumentException(sprintf(
				"Step '%s': the '%s' branch of a condition must be an array of steps, got %s",
				$type,
				$branch,
				get_debug_type($raw),
			));
		}

		return $raw;
	}

	/**
	 * @return array<string, StepConfig[]> port id => the single branch leaving the port
	 */
	private static function parseBranchMap(string $type, mixed $raw): array
	{
		if (!is_array($raw))
		{
			throw new \InvalidArgumentException("Step '$type': 'branches' must be an array");
		}

		$branches = [];
		foreach ($raw as $port => $branchSteps)
		{
			self::assertPortId($type, 'branch', $port);
			if (!is_array($branchSteps))
			{
				throw new \InvalidArgumentException("Step '$type': branch '$port' must be an array of steps");
			}
			$branches[$port] = array_map([self::class, 'fromMixed'], $branchSteps);
		}

		return $branches;
	}

	/**
	 * @return array<string, StepConfig[][]> port id => branches leaving the port
	 */
	private static function parseFanoutMap(string $type, mixed $raw): array
	{
		if (!is_array($raw))
		{
			throw new \InvalidArgumentException("Step '$type': 'fanout' must be an array");
		}
		if ($raw === [])
		{
			throw new \InvalidArgumentException("Step '$type': 'fanout' must declare at least one output port");
		}

		$fanout = [];
		foreach ($raw as $port => $branches)
		{
			self::assertPortId($type, 'fanout', $port);
			$fanout[$port] = self::parseFanoutBranches("Step '$type' port '$port'", $branches);
		}

		return $fanout;
	}

	/**
	 * @return array<string, StepConfig[]> aux port id => nodes bound to the port, order significant
	 */
	private static function parseAttachmentMap(string $type, mixed $raw): array
	{
		if (!is_array($raw))
		{
			throw new \InvalidArgumentException("Step '$type': 'attachments' must be an array");
		}

		$attachments = [];
		foreach ($raw as $port => $boundNodes)
		{
			self::assertPortId($type, 'attachment', $port, ActivityPortType::Aux);
			if (!is_array($boundNodes) || !array_is_list($boundNodes))
			{
				throw new \InvalidArgumentException(
					"Step '$type': attachments of port '$port' must be a list of nodes",
				);
			}
			$attachments[$port] = array_map(
				static fn(mixed $node): self => self::attachmentFromMixed($type, (string)$port, $node),
				$boundNodes,
			);
		}

		return $attachments;
	}

	/**
	 * A bound node is a step of its own minus the continuation: it hangs on an aux port of its owner -
	 * a knowledge base, a set of tools the activity uses - and wires nothing further. Only the node
	 * keys and the properties of the activity are left, and the refusal names the key that says
	 * otherwise instead of dropping it.
	 */
	private static function attachmentFromMixed(string $ownerType, string $port, mixed $data): self
	{
		$context = "Step '$ownerType': attachment on port '$port'";
		$type = is_array($data) ? array_key_first($data) : null;

		if ($type === self::REF_KEY)
		{
			throw new \InvalidArgumentException(sprintf(
				"%s must declare the node it binds, and '%s' points at a node declared elsewhere",
				$context,
				self::REF_KEY,
			));
		}

		if (is_string($type))
		{
			self::assertBindingStatesItsNodeAndNothingElse($context, $type, $data);
		}

		return self::fromMixed($data);
	}

	/**
	 * Everything a binding says about its node it says in the body of that node, and the keys that continue
	 * the flow or name an activity inside a complex wrapper mean nothing to a bound node at all. A key
	 * beside the type of the node is refused the way {@see self::refFromArray()} refuses one beside '_ref':
	 * the parse reads the body of the type key alone, so anything next to it would be lost silently.
	 *
	 * The body itself has to be a map: the parse takes anything else for no body at all and creates the node
	 * without the data the binding does state. A bound node with nothing to state is the shorthand of a step
	 * without properties - the type of the activity written as a string - so no other form is left to mean it.
	 *
	 * @param array<mixed, mixed> $data
	 */
	private static function assertBindingStatesItsNodeAndNothingElse(string $context, string $type, array $data): void
	{
		$keysBesideType = array_diff(array_keys($data), [$type]);
		if (!empty($keysBesideType))
		{
			throw new \InvalidArgumentException(sprintf(
				"%s: '%s' must be the only key of a bound node, got also [%s]",
				$context,
				$type,
				implode(', ', $keysBesideType),
			));
		}

		$body = $data[$type] ?? null;
		if (!is_array($body))
		{
			throw new \InvalidArgumentException(sprintf(
				"%s: node '%s' must state its body as a map of the node keys and the properties of the activity,"
					. " got %s - a bound node with nothing to state is written as the string '%s', and a body of"
					. ' another type would be read as a node stating nothing at all',
				$context,
				$type,
				get_debug_type($body),
				$type,
			));
		}

		$bodyKeys = array_keys($body);

		$continuationKeys = array_intersect($bodyKeys, self::CONTINUATION_KEYS);
		if (!empty($continuationKeys))
		{
			throw new \InvalidArgumentException(sprintf(
				"%s: node '%s' has key(s) [%s], and a bound node continues nothing",
				$context,
				$type,
				implode(', ', $continuationKeys),
			));
		}

		$innerActivityKeys = array_intersect($bodyKeys, self::INNER_ACTIVITY_KEYS);
		if (!empty($innerActivityKeys))
		{
			throw new \InvalidArgumentException(sprintf(
				"%s: node '%s' has key(s) [%s], and a bound node is no complex wrapper - it is built as the"
					. ' node it names, with no activity inside it',
				$context,
				$type,
				implode(', ', $innerActivityKeys),
			));
		}
	}

	/**
	 * Branches of a single fan-out: every element is a branch, a branch is a list of steps. Shared by
	 * the step level (branches per output port) and the flow level (branches of the trigger output).
	 *
	 * @return StepConfig[][]
	 */
	public static function parseFanoutBranches(string $context, mixed $raw): array
	{
		if (!is_array($raw) || !array_is_list($raw))
		{
			throw new \InvalidArgumentException("$context: 'fanout' must be a list of branches");
		}

		$branches = [];
		foreach ($raw as $index => $branchSteps)
		{
			if (!is_array($branchSteps) || !array_is_list($branchSteps))
			{
				throw new \InvalidArgumentException("$context: fanout branch #$index must be a list of steps");
			}
			$branches[] = array_map([self::class, 'fromMixed'], $branchSteps);
		}

		return $branches;
	}

	/**
	 * Ports come in two families, and each has its own constructs: control flow leaves the output ports
	 * ('branches', 'fanout'), the aux ports bind nodes to the activity ('attachments'). A port of the
	 * other family names the construct the author meant, so the refusal names it too.
	 */
	private static function assertPortId(
		string $type,
		string $construct,
		mixed $port,
		ActivityPortType $expected = ActivityPortType::Output,
	): void
	{
		[$pattern, $examples] = self::portFamily($expected);
		if (is_string($port) && preg_match($pattern, $port))
		{
			return;
		}

		$other = $expected === ActivityPortType::Output ? ActivityPortType::Aux : ActivityPortType::Output;
		[$otherPattern, , $otherHint] = self::portFamily($other);
		if (is_string($port) && preg_match($otherPattern, $port))
		{
			throw new \InvalidArgumentException(
				"Invalid $construct port '$port' in step '$type': $otherHint",
			);
		}

		throw new \InvalidArgumentException(
			"Invalid $construct port '$port' in step '$type'. Expected format: $examples",
		);
	}

	/**
	 * @return array{0: string, 1: string, 2: string} id pattern of the family, its examples and what a
	 *                                                port of the family is wired by
	 */
	private static function portFamily(ActivityPortType $type): array
	{
		return match ($type)
		{
			ActivityPortType::Output => [
				'/^o\d+$/',
				'o0, o1, ...',
				"an output port is no binding - the branches leaving it are written in 'branches' or 'fanout'",
			],
			ActivityPortType::Aux => [
				'/^a\d+$/',
				'a0, a1, ...',
				"an aux port is no branch - the nodes bound to it are written in 'attachments'",
			],
		};
	}

	/**
	 * The name a node states, as the value under the key that states it: a step without a name leaves the key
	 * out, so an explicit null is a value here and no name at all - and the caller, knowing whether the key
	 * stands there, is the one to tell the two apart. The key is named by the caller too: a step names its own
	 * node by '_id' and the activity a complex wrapper runs inside it by '_inner_id'.
	 */
	private static function validateId(mixed $id, string $key = '_id'): string
	{
		if (!is_string($id))
		{
			throw new \InvalidArgumentException("Activity $key must be a string, got " . get_debug_type($id));
		}

		if (!preg_match(self::ID_PATTERN, $id))
		{
			throw new \InvalidArgumentException("Invalid activity ID format: '$id'. Expected: A followed by four numeric segments separated by '_' (each segment >= 4 digits)");
		}

		return $id;
	}

	/**
	 * The type of the activity a complex wrapper runs inside itself. It names an activity of the registry, so a
	 * value of another type is refused and not cast the way it used to be: '"_inner_type": 5' named the activity
	 * '5', and the build went looking for that type - while an explicit null was read as no shorthand at all and
	 * the wrapper was built as a plain node, with no activity inside it.
	 */
	private static function validateInnerType(string $type, mixed $innerType): string
	{
		if (!is_string($innerType))
		{
			throw new \InvalidArgumentException(sprintf(
				"Step '%s': '_inner_type' must be the type of the activity the wrapper runs inside itself, got %s",
				$type,
				get_debug_type($innerType),
			));
		}

		return $innerType;
	}
}
