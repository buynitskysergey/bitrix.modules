<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

use Bitrix\Bizproc\Activity\Enum\ActivityPortType;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\StepConfig;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\Bizproc\Internal\Service\SetupTemplate\SetupTemplateService;

/**
 * The criterion of round-trip equivalence: what a template built back from a reversed source differs in
 * from the template the reverse read (ALG-02).
 *
 * The comparison runs over the decoded templates, not over their bytes - the same node written by the
 * builder and by the designer may hold an empty object where the other holds an empty array, and a
 * difference of representation with one meaning must not be called drift. Two things the build restores
 * on its own are out of the comparison for the same reason: the position of a node on the canvas
 * (LayoutEngine lays it out) and the Title of a node the original states none for (the builder writes it
 * from the agent name). A Title the original does state is compared like any other property, and so is the
 * title the node carries on the canvas - the format has a key of its own for it, see getNodeFields().
 *
 * Beside the nodes and the links stands the data of the template itself - its name, its description, its
 * parameters, its variables, its constants and the activity all the nodes hang in. None of it is a node, so
 * getNodes() never reaches it, and all of it goes into the shipped bytes: see compareRootData() and
 * compareRootActivity().
 */
final class TemplateComparator
{
	/**
	 * Properties the build takes from elsewhere than the template, so the template says nothing about them.
	 * 'user' of the setup activity is derived from the trigger of the flow, and the build restores it even
	 * where the original template has none.
	 */
	private const REBUILT_PROPERTIES = [
		ActivityRegistry::SETUP_ACTIVITY_TYPE => ['user'],
	];

	/**
	 * The property of the setup activity holding the wizard of the agent. What the build restores approximately
	 * is the organization of it - where a block begins, its titles, its descriptions, its separators, the order
	 * of what stands in it - and that alone is a note of the report, never drift (see EquivalenceReport).
	 *
	 * The elements of the constants inside it are held exactly, field by field: the wizard decides what the
	 * person setting the agent up has to fill in and what it is filled with, so a field of an element that moved
	 * changes how the agent is installed. The format carries such a field ('wizard_required', 'wizard_default' of
	 * ConstantConfig), which is why the difference is drift and not an approximation. A constant standing there
	 * twice is neither drift nor a note: one element per constant is all the format writes, so the repetition is
	 * inexpressible and stops the round trip - see compareWizard().
	 */
	private const WIZARD_PROPERTY = [ActivityRegistry::SETUP_ACTIVITY_TYPE => 'blocks'];

	/** The item of a wizard block that stands for a constant, and the key naming the constant it stands for. */
	private const WIZARD_ELEMENT_TYPE = 'constant';
	private const WIZARD_ELEMENT_ID_KEY = 'id';

	/**
	 * Fields of an element of the wizard beside the two keys above: what the Constant of the setup activity
	 * (\Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity) writes about it. Compared with the
	 * normalization of the properties of a node - a value carrying nothing reads the same as no key at all -
	 * because the build states every one of them for every element it creates, so their presence is no
	 * statement of the template.
	 */
	private const WIZARD_ELEMENT_FIELDS = [
		'name',
		'constantType',
		'description',
		'multiple',
		'required',
		'options',
		'settings',
		'default',
	];

	/** Ports the source format can wire an edge into: everything else on a node is unreachable for it. */
	private const WIREABLE_INPUT_PORTS = ['i0', 'i1'];

	/**
	 * Ports of a composite step: its body hangs on the first one - the reverse walks the body from it and writes
	 * it down as one chain of steps (TemplateReverser::buildCompositeStep()) - and returns into the second,
	 * which is what marks a node as composite (ActivityRegistry::isComposite()).
	 */
	private const COMPOSITE_BODY_PORT = 'o0';
	private const COMPOSITE_RETURN_PORT = 'i1';

	/**
	 * The key the source format carries the title of a node on the canvas with: the reverse writes it among
	 * the properties of the step (TemplateReverser::cleanActivityProps()) and the build takes it back out of
	 * them (TemplateBuilder::takeNodeTitle()), so this is the name the report calls a moved title by.
	 */
	private const CANVAS_TITLE_KEY = 'NodeTitle';

	/** The nodes and the links of the template: compared as nodes and links, not as data of the root. */
	private const ROOT_NODES_FIELD = 'TEMPLATE';

	/**
	 * Root fields of the template the source format has a key of its own for. Nothing else may stand at the
	 * root: the build writes these five and the nodes, so a sixth field is beyond what the format can carry.
	 */
	private const EXPRESSIBLE_ROOT_FIELDS = ['NAME', 'DESCRIPTION', 'PARAMETERS', 'VARIABLES', 'CONSTANTS'];

	/**
	 * Root fields holding a map of name => the field of that name (\Bitrix\Bizproc\FieldType::normalizeProperty()).
	 * Compared entry by entry and inside an entry key by key - and a key of an entry is compared strictly, a
	 * value carrying nothing against no key at all included: see diffFieldKeys().
	 */
	private const ROOT_FIELD_SECTIONS = ['PARAMETERS', 'VARIABLES', 'CONSTANTS'];

	/**
	 * Keys of an activity the comparison reads elsewhere: the name is what the two templates match their nodes
	 * by, the type and the activation stand among the node fields, the canvas of the node is compared as node
	 * fields and as a visual descriptor, the properties as properties - and the children of a complex wrapper
	 * are activities of the template in their own right, which getNodes() walks and compares one by one.
	 *
	 * Everything else an activity carries is compared as a key of it, see compareNode(): the source states such
	 * a key in the tail of the step ('_activity' of TPL-05) or not at all. Taken from the format itself, so
	 * that the question "what of an activity does the format state elsewhere" has one answer for the parse,
	 * the reverse and this comparison.
	 */
	private const COMPARED_ACTIVITY_KEYS = StepConfig::ACTIVITY_KEYS_EXPRESSED_OTHERWISE;

	/** What the root activity of the template is: the format states neither, the build writes one pair for all. */
	private const ROOT_ACTIVITY_FIELDS = ['Type', 'Name'];

	/** What a root activity is made of: the build writes these four keys and the format states no other. */
	private const ROOT_ACTIVITY_KEYS = ['Type', 'Name', 'Properties', 'Children'];

	/** The property of the root activity the comparison reads elsewhere: its links are compared as links. */
	private const ROOT_LINKS_PROPERTY = 'Links';

	/** The one property of the root activity the format states - through 'root_title' of the document. */
	private const ROOT_TITLE_PROPERTY = 'Title';

	/** A deterministic service mark added for setup constants while building AI-agent templates. */
	private const CONSTANT_SOURCE_KEY = 'Source';

	public function compare(array $original, array $rebuilt): EquivalenceReport
	{
		$repeatedNames = [];
		$originalNodes = self::getNodes($original, $repeatedNames);
		$rebuiltNodes = self::getNodes($rebuilt, $repeatedNames);
		$dangling = self::getDanglingLinks($original, $originalNodes);
		$setupConstantIds = self::getSetupConstantIds($originalNodes);

		$propertyDiffs = [];
		$descriptorDiffs = [];
		$approximated = [];
		$inexpressible = [];
		foreach (array_unique($repeatedNames) as $name)
		{
			$inexpressible[] = sprintf(
				'%s: the template carries more than one activity under this name - the two templates are matched'
					. ' node by node under it and the build writes one node per name, so only the first activity of'
					. ' the name is compared and no source restores the rest',
				$name,
			);
		}

		foreach (array_keys($originalNodes) as $name)
		{
			if (!isset($rebuiltNodes[$name]))
			{
				continue;
			}

			self::compareNode(
				(string)$name,
				$originalNodes[$name],
				$rebuiltNodes[$name],
				$propertyDiffs,
				$descriptorDiffs,
				$approximated,
				$inexpressible,
			);
		}

		$originalLinks = self::getComparedLinks($original, $dangling);
		$rebuiltLinks = self::getComparedLinks($rebuilt, $dangling);
		$lostLinks = self::diffLinks($originalLinks, $rebuiltLinks);

		$inexpressible = array_merge($inexpressible, self::explainLostLinks($lostLinks, $original, $originalNodes));
		$rootDataDiffs = [];
		self::compareRootData($original, $rebuilt, $rootDataDiffs, $inexpressible, $setupConstantIds);
		self::compareRootActivity($original, $rebuilt, $propertyDiffs, $inexpressible);

		return new EquivalenceReport(
			lostNodes: self::getNames(array_diff_key($originalNodes, $rebuiltNodes)),
			extraNodes: self::getNames(array_diff_key($rebuiltNodes, $originalNodes)),
			lostLinks: $lostLinks,
			extraLinks: self::diffLinks($rebuiltLinks, $originalLinks),
			propertyDiffs: $propertyDiffs,
			descriptorDiffs: $descriptorDiffs,
			rootDataDiffs: $rootDataDiffs,
			danglingLinks: array_values($dangling),
			inexpressible: $inexpressible,
			approximated: $approximated,
		);
	}

	/**
	 * Data of the template beside its nodes: the name and the description of the agent, its parameters, its
	 * variables and its constants. Nothing of this is a node, so the walk of getNodes() never sees it - and a
	 * loss here moves the shipped bytes of the file and the constants a new installation is set up with. The
	 * revision of the agent stays where it was: it is a hash of the TEMPLATE tree alone, see EquivalenceReport.
	 *
	 * @param list<string> $rootDataDiffs
	 * @param list<string> $inexpressible
	 * @param array<string, true> $setupConstantIds
	 */
	private static function compareRootData(
		array $original,
		array $rebuilt,
		array &$rootDataDiffs,
		array &$inexpressible,
		array $setupConstantIds,
	): void
	{
		foreach (array_keys($original + $rebuilt) as $field)
		{
			$field = (string)$field;
			if ($field === self::ROOT_NODES_FIELD)
			{
				continue;
			}

			$originalValue = $original[$field] ?? null;
			$rebuiltValue = $rebuilt[$field] ?? null;

			if (!in_array($field, self::EXPRESSIBLE_ROOT_FIELDS, true))
			{
				// Whether the field is there at all is asked apart from what it carries, the way it is asked of
				// a key of a constant (diffFieldKeys()) and of a key of the root activity: a field the format
				// cannot write is gone from the rebuild, and normalizeValue() reads a field carrying nothing as
				// no field. Called clean, such a round trip would ship a source that never restores the field.
				if (
					array_key_exists($field, $original) !== array_key_exists($field, $rebuilt)
					|| self::normalizeValue($originalValue) !== self::normalizeValue($rebuiltValue)
				)
				{
					$inexpressible[] = sprintf(
						'%s: a field of the template beside its nodes - the format writes %s and nothing else there',
						$field,
						implode(', ', self::EXPRESSIBLE_ROOT_FIELDS),
					);
				}

				continue;
			}

			if (in_array($field, self::ROOT_FIELD_SECTIONS, true))
			{
				$rootDataDiffs = array_merge(
					$rootDataDiffs,
					self::diffFieldSection($field, $originalValue, $rebuiltValue, $setupConstantIds),
				);

				continue;
			}

			if (self::normalizeValue($originalValue) !== self::normalizeValue($rebuiltValue))
			{
				$rootDataDiffs[] = $field;
			}
		}
	}

	/**
	 * A section of fields: an entry the two sides disagree about is named by the key it stands under, and a
	 * difference inside an entry by the field of it that moved.
	 *
	 * @return list<string> "SECTION.name" or "SECTION.name: field", in the order of the template
	 */
	private static function diffFieldSection(
		string $section,
		mixed $original,
		mixed $rebuilt,
		array $setupConstantIds = [],
	): array
	{
		if (!is_array($original) || !is_array($rebuilt))
		{
			return self::normalizeValue($original) === self::normalizeValue($rebuilt) ? [] : [$section];
		}

		$diffs = [];

		foreach (array_keys($original + $rebuilt) as $name)
		{
			$originalField = $original[$name] ?? null;
			$rebuiltField = $rebuilt[$name] ?? null;

			if (is_array($originalField) && is_array($rebuiltField))
			{
				$isSetupConstant = $section === 'CONSTANTS' && isset($setupConstantIds[(string)$name]);
				foreach (self::diffFieldKeys($originalField, $rebuiltField, $isSetupConstant) as $field)
				{
					$diffs[] = $section . '.' . $name . ': ' . $field;
				}

				continue;
			}

			if (self::normalizeValue($originalField) !== self::normalizeValue($rebuiltField))
			{
				$diffs[] = $section . '.' . $name;
			}
		}

		return $diffs;
	}

	/**
	 * Keys of one field of a section - of a constant, a parameter or a variable - the two sides disagree about,
	 * and of the visual descriptor of a node. A value carrying nothing is told apart from no value at all here,
	 * unlike among the properties of a node: the shipped bytes hold that difference - 'Options' as an empty map
	 * against 'Options' as null, 'Settings' as an empty map against no such key, an icon stated as null against
	 * an icon left to the registry - and the format states it, see ConstantConfig and ActivityDescriptorConfig.
	 *
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 * @return list<string> sorted, so that two runs read the same
	 */
	private static function diffFieldKeys(array $left, array $right, bool $isSetupConstant = false): array
	{
		if ($isSetupConstant)
		{
			$left = self::withoutDeterministicConstantFields($left);
			$right = self::withoutDeterministicConstantFields($right);
		}

		$keys = [];

		foreach (array_keys($left + $right) as $key)
		{
			if (
				array_key_exists($key, $left) !== array_key_exists($key, $right)
				|| self::normalizeShape($left[$key] ?? null) !== self::normalizeShape($right[$key] ?? null)
			)
			{
				$keys[] = (string)$key;
			}
		}
		sort($keys, SORT_STRING);

		return $keys;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private static function withoutDeterministicConstantFields(array $field): array
	{
		if (($field[self::CONSTANT_SOURCE_KEY] ?? null) === SetupTemplateService::SETUP_CONSTANT_SOURCE)
		{
			unset($field[self::CONSTANT_SOURCE_KEY]);
		}

		return $field;
	}

	/**
	 * @param array<string, array<string, mixed>> $nodes
	 * @return array<string, true>
	 */
	private static function getSetupConstantIds(array $nodes): array
	{
		$ids = [];
		foreach ($nodes as $node)
		{
			if (($node['Type'] ?? null) !== ActivityRegistry::SETUP_ACTIVITY_TYPE)
			{
				continue;
			}

			$blocks = self::getProperties($node)[self::WIZARD_PROPERTY[ActivityRegistry::SETUP_ACTIVITY_TYPE]] ?? null;
			if (!is_array($blocks))
			{
				continue;
			}

			foreach (self::getWizardItems($blocks) as $item)
			{
				if (
					($item['itemType'] ?? null) === self::WIZARD_ELEMENT_TYPE
					&& isset($item[self::WIZARD_ELEMENT_ID_KEY])
				)
				{
					$ids[(string)$item[self::WIZARD_ELEMENT_ID_KEY]] = true;
				}
			}
		}

		return $ids;
	}

	/**
	 * A field of a section or of a node descriptor in the shape the comparison compares it in: how a map is
	 * represented is no statement of the template - an object and an array of the same pairs are one field, and so
	 * is the order of the keys inside it - while a value carrying nothing keeps the form it was written in, unlike
	 * in normalizeValue().
	 */
	private static function normalizeShape(mixed $value): mixed
	{
		if (is_object($value))
		{
			$value = (array)$value;
		}
		if (!is_array($value))
		{
			return $value;
		}

		$normalized = array_map(static fn(mixed $item): mixed => self::normalizeShape($item), $value);
		if (!array_is_list($normalized))
		{
			ksort($normalized, SORT_STRING);
		}

		return $normalized;
	}

	/**
	 * The activity the nodes of the template hang in. It is no node of the canvas, so getNodes() walks its
	 * children and leaves it itself out of the comparison - while the build writes it from the document alone:
	 * one type and one name for every agent, the links compared as links, and one property the format states,
	 * the title ('root_title' of the document).
	 *
	 * @param list<string> $propertyDiffs
	 * @param list<string> $inexpressible
	 */
	private static function compareRootActivity(
		array $original,
		array $rebuilt,
		array &$propertyDiffs,
		array &$inexpressible,
	): void
	{
		$originalRoot = self::getRootActivity($original);
		$rebuiltRoot = self::getRootActivity($rebuilt);
		$name = (string)($originalRoot['Name'] ?? '');

		foreach (self::ROOT_ACTIVITY_FIELDS as $field)
		{
			if (($originalRoot[$field] ?? null) === ($rebuiltRoot[$field] ?? null))
			{
				continue;
			}

			// One reason per activity: its name and its type come from the same place, and neither is in the source.
			$inexpressible[] = sprintf(
				"%s: the root activity of the template comes back as '%s' of type '%s' - the format states neither"
					. ' the name nor the type of it',
				$name,
				(string)($rebuiltRoot['Name'] ?? ''),
				(string)($rebuiltRoot['Type'] ?? ''),
			);
			break;
		}

		// A key of the activity itself beside the four the build writes: the format says nothing about it at all,
		// the way it says nothing about a sixth field at the root of the template - see compareRootData().
		foreach (array_keys($originalRoot + $rebuiltRoot) as $field)
		{
			$field = (string)$field;
			if (in_array($field, self::ROOT_ACTIVITY_KEYS, true))
			{
				continue;
			}

			if (
				array_key_exists($field, $originalRoot) === array_key_exists($field, $rebuiltRoot)
				&& self::normalizeValue($originalRoot[$field] ?? null)
					=== self::normalizeValue($rebuiltRoot[$field] ?? null)
			)
			{
				continue;
			}

			$inexpressible[] = sprintf(
				"%s: key '%s' of the root activity of the template - the build writes %s there and nothing else",
				$name,
				$field,
				implode(', ', self::ROOT_ACTIVITY_KEYS),
			);
		}

		$originalProperties = self::getProperties($originalRoot);
		$rebuiltProperties = self::getProperties($rebuiltRoot);
		unset($originalProperties[self::ROOT_LINKS_PROPERTY], $rebuiltProperties[self::ROOT_LINKS_PROPERTY]);

		foreach (self::diffKeys($originalProperties, $rebuiltProperties) as $property)
		{
			if ($property === self::ROOT_TITLE_PROPERTY)
			{
				$propertyDiffs[] = $name . ': ' . $property;

				continue;
			}

			$inexpressible[] = sprintf(
				"%s: property '%s' of the root activity of the template - the format states its title alone",
				$name,
				$property,
			);
		}
	}

	/**
	 * @return array<string, mixed> the activity the nodes hang in, empty when the template holds none
	 */
	private static function getRootActivity(array $template): array
	{
		$root = $template[self::ROOT_NODES_FIELD][0] ?? null;

		return is_array($root) ? $root : [];
	}

	/**
	 * @param array<string, mixed> $original
	 * @param array<string, mixed> $rebuilt
	 * @param list<string> $propertyDiffs
	 * @param list<string> $descriptorDiffs
	 * @param list<string> $approximated
	 * @param list<string> $inexpressible
	 */
	private static function compareNode(
		string $name,
		array $original,
		array $rebuilt,
		array &$propertyDiffs,
		array &$descriptorDiffs,
		array &$approximated,
		array &$inexpressible,
	): void
	{
		$wizardProperty = self::WIZARD_PROPERTY[(string)($original['Type'] ?? '')] ?? null;
		$originalProperties = self::getProperties($original);
		$rebuiltProperties = self::getProperties($rebuilt);

		// The title the build wrote for a node the original states no title for: the template says nothing
		// about it, so it is no difference. Only this direction is excused, see isAutoGeneratedTitle().
		if (
			!isset($originalProperties['Title'])
			&& self::isAutoGeneratedTitle($rebuiltProperties['Title'] ?? null, (string)($rebuilt['Type'] ?? ''))
		)
		{
			unset($rebuiltProperties['Title']);
		}

		foreach (self::diffKeys($originalProperties, $rebuiltProperties) as $property)
		{
			if ($property === $wizardProperty)
			{
				self::compareWizard(
					$name,
					$property,
					$originalProperties[$property] ?? null,
					$rebuiltProperties[$property] ?? null,
					$propertyDiffs,
					$approximated,
					$inexpressible,
				);

				continue;
			}

			$propertyDiffs[] = $name . ': ' . $property;
		}

		foreach (self::diffKeys(self::getNodeFields($original), self::getNodeFields($rebuilt)) as $field)
		{
			$propertyDiffs[] = $name . ': ' . $field;
		}

		// A field stated as null is told apart from a field not stated at all, the way the fields of a constant
		// are: the first writes null into the node, the second leaves the field to the registry (TPL-03), and
		// 'bitrix_ai_open_lines_operator' ships nodes whose icon and color index are null. One descriptor stands
		// for every node of its type, so a node whose key is missing while that descriptor states null cannot be
		// written down at all - and reading the two as one used to keep such a loss out of the report.
		foreach (self::diffFieldKeys(self::getDescriptor($original), self::getDescriptor($rebuilt)) as $field)
		{
			$descriptorDiffs[] = $name . ': ' . $field;
		}

		self::compareActivityKeys($name, $original, $rebuilt, $inexpressible);
	}

	/**
	 * The wizard of the setup activity, held against the rebuilt one in two ways at once. Its elements are
	 * matched by the constant they stand for and every field of an element is compared exactly: a field that
	 * moved changes what the person setting the agent up has to fill in, and the format states it. What is left -
	 * the organization of the wizard: the boundaries of its blocks, their titles, their descriptions, their
	 * separators and the order of what stands in them - the build restores approximately, and it makes one note.
	 *
	 * An element the two sides do not both carry is drift as well: an element of the wizard is how a constant is
	 * asked for at all, and losing it is no matter of organization.
	 *
	 * A constant standing in the wizard twice is neither: the document states its constants as a map of the name
	 * of a constant, and the build writes one element per constant, so the second element of the same constant is
	 * beyond what the format can write down at all. It used to leave the elements matching one by one and move
	 * the organization alone - a note, and notes do not decide isClean() - so a wizard the round trip cannot
	 * carry was written as a source of a shipped agent without a word.
	 *
	 * @param list<string> $propertyDiffs
	 * @param list<string> $approximated
	 * @param list<string> $inexpressible
	 */
	private static function compareWizard(
		string $name,
		string $property,
		mixed $original,
		mixed $rebuilt,
		array &$propertyDiffs,
		array &$approximated,
		array &$inexpressible,
	): void
	{
		if (!is_array($original) || !is_array($rebuilt))
		{
			// One side carries no wizard at all: nothing to match the elements of the other side by.
			$propertyDiffs[] = $name . ': ' . $property;

			return;
		}

		$repeatedIds = [];
		$originalElements = self::getWizardElements($original, $repeatedIds);
		$rebuiltElements = self::getWizardElements($rebuilt, $repeatedIds);

		foreach (array_unique($repeatedIds) as $id)
		{
			$inexpressible[] = sprintf(
				'%s: %s[%s] stands in the wizard more than once - the format writes one element per constant, so'
					. ' only the first of them comes back and no source states the rest',
				$name,
				$property,
				$id,
			);
		}

		foreach (array_keys($originalElements + $rebuiltElements) as $id)
		{
			$element = sprintf('%s: %s[%s]', $name, $property, (string)$id);

			if (!isset($originalElements[$id], $rebuiltElements[$id]))
			{
				$propertyDiffs[] = $element;

				continue;
			}

			foreach (self::WIZARD_ELEMENT_FIELDS as $field)
			{
				if (
					self::normalizeValue($originalElements[$id][$field] ?? null)
					!== self::normalizeValue($rebuiltElements[$id][$field] ?? null)
				)
				{
					$propertyDiffs[] = $element . '.' . $field;
				}
			}
		}

		if (self::getWizardOrganization($original) !== self::getWizardOrganization($rebuilt))
		{
			$approximated[] = $name . ': ' . $property;
		}
	}

	/**
	 * Elements of the wizard by the constant they stand for. An element declared twice is taken by its first
	 * declaration, the way the reverse reads it: the build writes one element per constant. The repetition is
	 * handed back to the caller instead of being swallowed here - see compareWizard(), which reports it.
	 *
	 * @param list<mixed> $blocks
	 * @param list<string> $repeatedIds constants standing in the wizard more than once
	 *
	 * @return array<string, array<string, mixed>> id of the constant => element of the wizard
	 */
	private static function getWizardElements(array $blocks, array &$repeatedIds): array
	{
		$elements = [];

		foreach (self::getWizardItems($blocks) as $item)
		{
			if (
				($item['itemType'] ?? null) === self::WIZARD_ELEMENT_TYPE
				&& isset($item[self::WIZARD_ELEMENT_ID_KEY])
			)
			{
				$id = (string)$item[self::WIZARD_ELEMENT_ID_KEY];
				if (array_key_exists($id, $elements))
				{
					$repeatedIds[] = $id;
				}
				else
				{
					$elements[$id] = $item;
				}
			}
		}

		return $elements;
	}

	/**
	 * The wizard with its elements reduced to the constants they stand for: what is left of it is its
	 * organization, and that is the part the build restores approximately. The fields of an element are held
	 * exactly elsewhere, so they must not make an approximated note of their own.
	 *
	 * @param list<mixed> $blocks
	 * @return list<list<mixed>> blocks, each an ordered list of what stands in it
	 */
	private static function getWizardOrganization(array $blocks): array
	{
		$organization = [];

		foreach ($blocks as $block)
		{
			$items = [];
			foreach (self::getBlockItems($block) as $item)
			{
				$items[] = is_array($item) && ($item['itemType'] ?? null) === self::WIZARD_ELEMENT_TYPE
					? [
						'itemType' => self::WIZARD_ELEMENT_TYPE,
						self::WIZARD_ELEMENT_ID_KEY => $item[self::WIZARD_ELEMENT_ID_KEY] ?? null,
					]
					: self::normalizeValue($item);
			}
			$organization[] = $items;
		}

		return $organization;
	}

	/**
	 * @param list<mixed> $blocks
	 * @return list<array<string, mixed>> items of every block, in the order of the wizard
	 */
	private static function getWizardItems(array $blocks): array
	{
		$items = [];

		foreach ($blocks as $block)
		{
			foreach (self::getBlockItems($block) as $item)
			{
				if (is_array($item))
				{
					$items[] = $item;
				}
			}
		}

		return $items;
	}

	/**
	 * What stands in one block of the wizard. A block holding nothing answers with an empty list either way:
	 * the properties reach the comparison normalized, and an empty list of items stands there as null.
	 *
	 * @return list<mixed>
	 */
	private static function getBlockItems(mixed $block): array
	{
		$items = is_array($block) ? ($block['items'] ?? null) : null;

		return is_array($items) ? array_values($items) : [];
	}

	/**
	 * A key of the activity itself beside the ones compared above: 'Document', the return properties of an
	 * activity a complex wrapper runs inside itself, whatever else the designer wrote there. The source states
	 * such a key in the tail of the step ('_activity' of TPL-05) and nowhere else, so a key that came back
	 * otherwise is one the round trip did not carry - the way a sixth field at the root of the template is,
	 * see compareRootData() and compareRootActivity().
	 *
	 * A key present on one side alone counts as a difference even where both sides carry nothing: a node
	 * without a 'Document' key and a node whose 'Document' is null are two different templates, and the
	 * revision of the agent is a hash of the tree as it stands (TemplateRevisionService::calculateRevision()).
	 *
	 * @param array<string, mixed> $original
	 * @param array<string, mixed> $rebuilt
	 * @param list<string> $inexpressible
	 */
	private static function compareActivityKeys(
		string $name,
		array $original,
		array $rebuilt,
		array &$inexpressible,
	): void
	{
		foreach (array_keys($original + $rebuilt) as $key)
		{
			$key = (string)$key;
			if (in_array($key, self::COMPARED_ACTIVITY_KEYS, true))
			{
				continue;
			}

			if (
				array_key_exists($key, $original) === array_key_exists($key, $rebuilt)
				&& self::normalizeValue($original[$key] ?? null) === self::normalizeValue($rebuilt[$key] ?? null)
			)
			{
				continue;
			}

			$inexpressible[] = sprintf(
				"%s: key '%s' of the activity - what a node carries beside its type, its properties and its canvas"
					. " is stated by the tail of the step ('%s'), and this key came back otherwise",
				$name,
				$key,
				StepConfig::ACTIVITY_KEY,
			);
		}
	}

	/**
	 * Every activity of the template, the inner activities of complex wrappers included: an inner activity
	 * is a node of the template too, and a round trip that loses it loses the tool the wrapper runs.
	 *
	 * Walked to the bottom, because a complex wrapper may run a complex wrapper: one level of children used to
	 * be the whole walk, so everything under a wrapper standing inside another one stayed out of the comparison
	 * - the reverse writes such children out of the rules of the wrapper, and a difference among them left the
	 * report with nothing at all to say.
	 *
	 * @param list<string> $repeatedNames names carried by more than one activity, the caller reports them
	 *
	 * @return array<string, array<string, mixed>> node name => activity, in template order
	 */
	private static function getNodes(array $template, array &$repeatedNames): array
	{
		$nodes = [];

		foreach (self::getRootActivities($template) as $activity)
		{
			self::collectNodes($activity, $nodes, $repeatedNames);
		}

		return $nodes;
	}

	/**
	 * An activity and everything running inside it. The first activity of a name is the one kept: the two
	 * templates are matched node by node under that name, so a second activity of the same name would take the
	 * place of the first and leave it compared against nothing. Templates hold their names unique - the build
	 * refuses the second (ActivityNodeBuilder::takeName()) - and the repetition is answered instead of relied on.
	 *
	 * @param array<string, mixed> $activity
	 * @param array<string, array<string, mixed>> $nodes
	 * @param list<string> $repeatedNames
	 */
	private static function collectNodes(array $activity, array &$nodes, array &$repeatedNames): void
	{
		$name = (string)$activity['Name'];
		if (array_key_exists($name, $nodes))
		{
			$repeatedNames[] = $name;
		}
		else
		{
			$nodes[$name] = $activity;
		}

		foreach ($activity['Children'] ?? [] as $inner)
		{
			if (is_array($inner) && isset($inner['Name']) && is_string($inner['Name']))
			{
				self::collectNodes($inner, $nodes, $repeatedNames);
			}
		}
	}

	/**
	 * The walk of getNodes() starts here and this is its only reader: what counts as a node of the template is
	 * answered in one place, so no part of the comparison can go back to looking at the canvas alone.
	 *
	 * @return list<array<string, mixed>> root-level activities, i.e. the nodes drawn on the canvas
	 */
	private static function getRootActivities(array $template): array
	{
		$activities = [];

		foreach ($template['TEMPLATE'][0]['Children'] ?? [] as $activity)
		{
			if (is_array($activity) && isset($activity['Name']) && is_string($activity['Name']))
			{
				$activities[] = $activity;
			}
		}

		return $activities;
	}

	/**
	 * @param array<string, array<string, mixed>> $nodes
	 * @return list<string>
	 */
	private static function getNames(array $nodes): array
	{
		return array_map(static fn(int|string $name): string => (string)$name, array_keys($nodes));
	}

	/**
	 * Properties of an activity as the comparison sees them: without the ones the build restores from
	 * elsewhere and without an empty designer comment (the reverse drops it and the build writes none).
	 * The Title stays here - whether the build wrote it by itself is a question about both sides of the
	 * comparison at once, and compareNode() answers it.
	 *
	 * @param array<string, mixed> $activity
	 * @return array<string, mixed>
	 */
	private static function getProperties(array $activity): array
	{
		$properties = $activity['Properties'] ?? [];
		if (is_object($properties))
		{
			$properties = (array)$properties;
		}
		if (!is_array($properties))
		{
			return [];
		}

		$activityType = (string)($activity['Type'] ?? '');
		foreach (self::REBUILT_PROPERTIES[$activityType] ?? [] as $property)
		{
			unset($properties[$property]);
		}

		if (($properties['EditorComment'] ?? null) === '')
		{
			unset($properties['EditorComment']);
		}

		return array_map(static fn(mixed $value): mixed => self::normalizeValue($value), $properties);
	}

	/**
	 * Data of the node itself the comparison holds against the rebuilt node next to the properties of the
	 * activity: what the node is, whether it is active, and what it is called on the canvas. None is a property
	 * of the activity, and the title is no field of the visual descriptor - TPL-03 keeps titles out of it - so
	 * each is named by the key that carries it and the report says at once which one moved.
	 *
	 * The Type is here because the nodes of the two templates are matched by their name: a type resolved to
	 * another activity than before (ActivityRegistry::TYPE_MAP) would otherwise leave the report clean while
	 * the agent runs something else. Activated is compared against 'Y' when absent because every node the builder
	 * creates is active; an explicit 'N' changes whether the activity runs and cannot be dropped. The canvas title
	 * is compared as it stands, without the excuse the Title of the activity has: the build gives a canvas title
	 * to every node it creates. A title the round trip did not carry - emptied, or left to the Title of the
	 * activity - goes into the shipped bytes and therefore into
	 * the revision of the agent. A node drawn nowhere, the inner activity of a complex wrapper, states no title
	 * on either side and stays out.
	 *
	 * @param array<string, mixed> $activity
	 * @return array<string, mixed>
	 */
	private static function getNodeFields(array $activity): array
	{
		$fields = [
			'Type' => $activity['Type'] ?? null,
			'Activated' => $activity['Activated'] ?? 'Y',
		];

		// Guarded like getDescriptor(): a node drawn nowhere has no 'Node' to read the inner fields from.
		$canvas = $activity['Node'] ?? null;
		$node = is_array($canvas) && is_array($canvas['node'] ?? null) ? $canvas['node'] : [];
		if (array_key_exists('type', $node))
		{
			$fields['Node.node.type'] = $node['type'];
		}
		if (array_key_exists('title', $node))
		{
			$fields[self::CANVAS_TITLE_KEY] = $node['title'];
		}

		return $fields;
	}

	/**
	 * The title the builder writes for a node the source left without one: '###<lang prefix><TYPE>_TITLE###',
	 * where the prefix is the name of the agent in upper case (TemplateBuilder::getActivityTitle()). The name
	 * itself is unknown here - the comparator reads the two templates and asks nothing else - so what the
	 * check allows in its place is the shape of a lang prefix, and nothing wider: a name is written in
	 * lowercase letters, digits and underscores (TPL-06), the build upper-cases it.
	 *
	 * A title the original does state is never called generated, however much it reads like one - that is why
	 * compareNode() asks this about the rebuilt node alone. The reverse carries such a title into the source
	 * verbatim, so the build has to write it back unchanged; excusing it by its shape would let a title
	 * written by hand fall out of the report, and the agent would ship with the title of the build instead.
	 */
	private static function isAutoGeneratedTitle(mixed $title, string $activityType): bool
	{
		if ($activityType === '' || !is_string($title))
		{
			return false;
		}

		return (bool)preg_match(
			'/^###[A-Z0-9_]*' . preg_quote(strtoupper($activityType), '/') . '_TITLE###$/',
			$title,
		);
	}

	/**
	 * A property value in the shape the comparison compares: an empty object and an empty array carry the
	 * same meaning as no value at all, and the order of the keys inside an associative array is not a
	 * statement of the template - ConditionConfig::toArray() writes the keys of a condition in an order of
	 * its own, with the same values. The order of a list is kept: it is the order of the ports, blocks and
	 * conditions themselves.
	 */
	private static function normalizeValue(mixed $value): mixed
	{
		if (is_object($value))
		{
			$value = (array)$value;
		}
		if (!is_array($value))
		{
			return $value;
		}
		if ($value === [])
		{
			return null;
		}

		$normalized = array_map(static fn(mixed $item): mixed => self::normalizeValue($item), $value);
		if (!array_is_list($normalized))
		{
			ksort($normalized, SORT_STRING);
		}

		return $normalized;
	}

	/**
	 * Visual descriptor of a node: the fields TPL-03 states about it, its position aside - the position is
	 * laid out by LayoutEngine and no round trip carries it. Read through NodeDescriptorExtractor on
	 * purpose: a comparator holding its own copy of where each field sits inside 'Node' would drift apart
	 * from the reverse and start calling a round trip clean while a field is being dropped.
	 *
	 * @param array<string, mixed> $activity
	 * @return array<string, mixed>
	 */
	private static function getDescriptor(array $activity): array
	{
		// Inner activities of a complex wrapper have no 'Node': they are not drawn on the canvas.
		if (!is_array($activity['Node'] ?? null))
		{
			return [];
		}

		$descriptors = (new NodeDescriptorExtractor([$activity]))->getActivityDescriptors();

		return $descriptors[(string)($activity['Type'] ?? '')] ?? [];
	}

	/**
	 * Keys the two sides disagree about. A key that is absent on one side counts as null on it: a value
	 * carrying nothing - an empty array, an empty object, no key at all - is the same statement three times.
	 *
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 * @return list<string> sorted, so that two runs read the same
	 */
	private static function diffKeys(array $left, array $right): array
	{
		$keys = [];

		foreach (array_keys($left + $right) as $key)
		{
			if (($left[$key] ?? null) !== ($right[$key] ?? null))
			{
				$keys[] = (string)$key;
			}
		}
		sort($keys, SORT_STRING);

		return $keys;
	}

	/**
	 * Links of the template that enter the comparison: everything except the dangling links of the original,
	 * which the reverse drops knowingly.
	 *
	 * @param array<string, string> $dangling normalized link => report entry
	 * @return list<string> sorted normalized links
	 */
	private static function getComparedLinks(array $template, array $dangling): array
	{
		$links = [];

		foreach (self::getRawLinks($template) as $link)
		{
			$normalized = self::normalizeLink($link);
			if ($normalized === null || isset($dangling[$normalized]))
			{
				continue;
			}

			$links[] = $normalized;
		}
		sort($links, SORT_STRING);

		return $links;
	}

	/**
	 * @return list<array> raw Links entries of the template
	 */
	private static function getRawLinks(array $template): array
	{
		$links = [];

		foreach ($template['TEMPLATE'][0]['Properties']['Links'] ?? [] as $link)
		{
			if (is_array($link) && isset($link[0], $link[1]))
			{
				$links[] = $link;
			}
		}

		return $links;
	}

	/**
	 * A link as "from:port -> to:port". The third element of a link is a timestamp the designer writes and
	 * the build does not restore, so it is no part of the comparison.
	 *
	 * @param array $link
	 */
	private static function normalizeLink(array $link): ?string
	{
		if (!isset($link[0], $link[1]))
		{
			return null;
		}

		return (string)$link[0] . ' -> ' . (string)$link[1];
	}

	/**
	 * @param list<string> $links
	 * @param list<string> $against
	 * @return list<string>
	 */
	private static function diffLinks(array $links, array $against): array
	{
		return array_values(array_diff($links, $against));
	}

	/**
	 * Links of the original with an end that is no node of the template. The reverse drops them - it must
	 * not invent an edge around the unknown end - so they are left out of the comparison and reported as
	 * they are, together with which end is missing.
	 *
	 * Which nodes the template has is answered by the caller and never asked a second time here: this walk used
	 * to look at the root level alone while getNodes() reached every depth, and a link into the activity of a
	 * complex wrapper was called dangling because of it - dropped from the obligatory comparison, decided by no
	 * criterion, and lost by a round trip that reported nothing. One answer to what a node of the template is
	 * keeps the two from drifting apart again.
	 *
	 * @param array<string, array<string, mixed>> $nodes nodes of the template, see getNodes()
	 *
	 * @return array<string, string> normalized link => report entry
	 */
	private static function getDanglingLinks(array $template, array $nodes): array
	{
		$knownNodes = array_fill_keys(array_keys($nodes), true);

		$dangling = [];
		foreach ((new LinkGraph(self::getRawLinks($template), $knownNodes))->danglingLinks() as $link)
		{
			$normalized = $link['from'] . ' -> ' . $link['to'];
			$dangling[$normalized] = sprintf('%s (unknown end: %s)', $normalized, $link['unknownEnd']);
		}

		return $dangling;
	}

	/**
	 * Why a link disappeared, where the comparison can attribute it to a construct the source format has no
	 * way to write down. A lost link tells what disappeared; this tells why, and names the node to look at.
	 * Nothing is said about a link the format could have expressed: such a loss is drift of the reverse
	 * itself, and inventing a reason for it would hide that.
	 *
	 * @param list<string> $lostLinks
	 * @param array<string, array<string, mixed>> $nodes nodes of the original
	 * @return list<string>
	 */
	private static function explainLostLinks(array $lostLinks, array $original, array $nodes): array
	{
		if ($lostLinks === [])
		{
			return [];
		}

		$bindingOwners = self::countBindingOwners($original, $nodes);
		$portTargets = self::countTargetsPerPort($original);
		$reasons = [];

		foreach ($lostLinks as $link)
		{
			$reason = self::explainLostLink($link, $nodes, $bindingOwners, $portTargets);
			// One reason per construct: several links of the same node say the same thing about it.
			if ($reason !== null)
			{
				$reasons[$reason] = true;
			}
		}

		return array_keys($reasons);
	}

	/**
	 * @param array<string, array<string, mixed>> $nodes
	 * @param array<string, int> $bindingOwners
	 * @param array<string, int> $portTargets endpoint => edges leaving it
	 * @return string|null null when the format could have expressed this link, i.e. the loss is drift
	 */
	private static function explainLostLink(
		string $link,
		array $nodes,
		array $bindingOwners,
		array $portTargets,
	): ?string
	{
		[$from, $to] = self::splitLink($link);
		[$fromName, $fromPort] = self::splitEndpoint($from);
		[$toName, $toPort] = self::splitEndpoint($to);

		// A body of a composite step is written down as one chain of steps and the format allows no fan-out
		// beside it (ALG-02), so a second edge on the body port takes the whole body out of the source.
		if (
			$fromPort === self::COMPOSITE_BODY_PORT
			&& ($portTargets[$from] ?? 0) > 1
			&& self::isCompositeStep($nodes[$fromName] ?? [])
		)
		{
			return sprintf(
				"%s: %d edges leave port '%s' into the body of the step - the format writes a body as one chain"
					. ' of steps and no fan-out beside it, so the step is written with no body at all',
				$fromName,
				$portTargets[$from],
				$fromPort,
			);
		}

		$portType = self::getDeclaredPortType($nodes[$toName] ?? [], $toPort);

		if ($portType === null)
		{
			// The node declares no ports, so there is no telling whether this edge was wireable at all.
			return null;
		}

		if ($portType === ActivityPortType::TopAux)
		{
			return ($bindingOwners[$toName] ?? 0) > 1
				? sprintf(
					'%s: bound to %d nodes through their aux ports - the format declares a bound node where'
						. ' it hangs, so only the first of the bindings survives',
					$toName,
					$bindingOwners[$toName],
				)
				: null;
		}

		if (in_array($toPort, self::WIREABLE_INPUT_PORTS, true))
		{
			return null;
		}

		return sprintf(
			"%s: an edge into port '%s' - the format wires an edge only into %s and a top aux port",
			$toName,
			$toPort,
			implode(', ', self::WIREABLE_INPUT_PORTS),
		);
	}

	/**
	 * Whether the node is a composite step: the body of one is drawn beside it and returns into its 'i1' port,
	 * and only a composite declares that port. Read off the template alone - asking the registry which types are
	 * composite would make the report depend on which activity-owning modules are installed here.
	 *
	 * @param array<string, mixed> $activity
	 */
	private static function isCompositeStep(array $activity): bool
	{
		return self::getDeclaredPortType($activity, self::COMPOSITE_RETURN_PORT) === ActivityPortType::Input;
	}

	/**
	 * How many edges leave every endpoint of the template. A port the format reads as one continuation says
	 * with a second edge on it that the construct behind it cannot be written down.
	 *
	 * @return array<string, int> "node name:port id" => edges leaving it
	 */
	private static function countTargetsPerPort(array $template): array
	{
		$counts = [];

		foreach (self::getRawLinks($template) as $link)
		{
			$from = (string)$link[0];
			$counts[$from] = ($counts[$from] ?? 0) + 1;
		}

		return $counts;
	}

	/**
	 * How many nodes bind to a node through their aux ports. More than one is a construct the format cannot
	 * write down: a bound node is declared where it hangs, so a second declaration of it would build a
	 * second node.
	 *
	 * @param array<string, array<string, mixed>> $nodes
	 * @return array<string, int> node name => owners binding to it
	 */
	private static function countBindingOwners(array $template, array $nodes): array
	{
		$owners = [];

		foreach (self::getRawLinks($template) as $link)
		{
			[$fromName] = self::splitEndpoint((string)$link[0]);
			[$toName, $toPort] = self::splitEndpoint((string)$link[1]);

			if (self::getDeclaredPortType($nodes[$toName] ?? [], $toPort) === ActivityPortType::TopAux)
			{
				$owners[$toName][$fromName] = true;
			}
		}

		return array_map('count', $owners);
	}

	/**
	 * Type of a port as the node itself declares it. A node that declares no ports at all answers null: the
	 * comparator reads the template alone - asking the registry would make the report depend on which
	 * activity-owning modules are installed here.
	 *
	 * @param array<string, mixed> $activity
	 */
	private static function getDeclaredPortType(array $activity, string $portId): ?ActivityPortType
	{
		foreach ($activity['Node']['ports'] ?? [] as $port)
		{
			if (is_array($port) && ($port['id'] ?? null) === $portId)
			{
				return ActivityPortType::tryFrom((string)($port['type'] ?? ''));
			}
		}

		return null;
	}

	/**
	 * @return array{0: string, 1: string} source and target endpoints of a "from:port -> to:port" link
	 */
	private static function splitLink(string $link): array
	{
		$parts = explode(' -> ', $link, 2);

		return [$parts[0] ?? '', $parts[1] ?? ''];
	}

	/**
	 * @return array{0: string, 1: string} node name and port id of a "name:port" endpoint
	 */
	private static function splitEndpoint(string $endpoint): array
	{
		$parts = explode(':', $endpoint, 2);

		return [$parts[0] ?? '', $parts[1] ?? ''];
	}
}
