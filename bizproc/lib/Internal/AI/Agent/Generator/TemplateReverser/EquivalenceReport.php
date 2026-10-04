<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

/**
 * What one round trip of a template changed: the difference between the original template and the template
 * built back from the source the reverse wrote (DTO-01).
 *
 * The lists fall into two kinds, and each is named by the fields it holds - not by where they stand, because
 * the order of the parameters is fixed by DTO-01 and says nothing about the kind. Drift - the round trip lost
 * or invented something - is lostNodes, extraNodes, lostLinks, extraLinks, propertyDiffs, descriptorDiffs,
 * rootDataDiffs and inexpressible; these eight decide isClean(). Notes are danglingLinks and approximated: a
 * dangling link was dropped knowingly, and an approximated property is one whose organization the build restores
 * in a shape of its own - the blocks of the setup wizard, whose elements are nevertheless compared exactly. The
 * notes are kept apart from the drift on purpose, so that a deliberate simplification is never read as lost
 * data; what each of them costs the person committing the source is told by the reverse command.
 *
 * rootDataDiffs is the one list DTO-01 did not name. It was added because the data of the template beside its
 * nodes - NAME, DESCRIPTION, PARAMETERS, VARIABLES, CONSTANTS - belongs to no node and to no link, so none of
 * the eight other lists could hold it. A loss there moves the shipped bytes of the file and the constants a
 * new installation of the agent is set up with; the revision of the agent it does not move - that is a hash of
 * the TEMPLATE tree alone (TemplateRevisionService::calculateRevision()), which is why a loss inside a node -
 * the 'blocks' of the setup wizard, for one - is the graver one of the two. The properties of the root activity
 * are no part of this list: that activity has a name, so what it lost is named by propertyDiffs like the
 * property of any other node.
 *
 * Equivalence here is structural: the comparison runs over the decoded templates, never over their bytes.
 * Byte identity is a criterion of its own and only for the direction "source -> template", see
 * TemplateGoldenBuildTest - the two must not be mixed.
 */
final readonly class EquivalenceReport
{
	/**
	 * @param list<string> $lostNodes names of nodes of the original the rebuilt template has no more,
	 *                                inner activities of complex wrappers included
	 * @param list<string> $extraNodes names of nodes the rebuild added
	 * @param list<string> $lostLinks "from:port -> to:port" links of the original the rebuild did not produce
	 * @param list<string> $extraLinks links the rebuild produced and the original had not
	 * @param list<string> $propertyDiffs "node name: property name" - a property of the activity differs, or
	 *                                    the node states something else than it did: see
	 *                                    TemplateComparator::getNodeFields()
	 * @param list<string> $descriptorDiffs "node name: field" - the visual descriptor of the node differs
	 * @param list<string> $rootDataDiffs the field of the template that differs, or "SECTION.name: field" of a
	 *                                    parameter, a variable or a constant: see TemplateComparator::compareRootData()
	 * @param list<string> $danglingLinks a note: links of the original with an end outside the template - the
	 *                                    reverse drops them, and they take no part in the comparison
	 * @param list<string> $inexpressible "node name: reason" - constructs the source format cannot write down
	 * @param list<string> $approximated a note: "node name: property name" - a property whose organization the
	 *                                   build restores in a shape of its own: the 'blocks' of the setup wizard,
	 *                                   where a block may begin elsewhere and a title, a description or a
	 *                                   separator may be gone. What the wizard asks for element by element is no
	 *                                   part of this note - that is compared exactly and lands in propertyDiffs,
	 *                                   see TemplateComparator::compareWizard()
	 */
	public function __construct(
		public array $lostNodes,
		public array $extraNodes,
		public array $lostLinks,
		public array $extraLinks,
		public array $propertyDiffs,
		public array $descriptorDiffs,
		public array $rootDataDiffs,
		public array $danglingLinks,
		public array $inexpressible,
		public array $approximated,
	) {}

	/**
	 * Whether the round trip carried the template over without drift. Dangling links and approximated
	 * properties do not count: both are known simplifications, and a report of them is information, not
	 * a reason to distrust the source.
	 */
	public function isClean(): bool
	{
		return $this->lostNodes === []
			&& $this->extraNodes === []
			&& $this->lostLinks === []
			&& $this->extraLinks === []
			&& $this->propertyDiffs === []
			&& $this->descriptorDiffs === []
			&& $this->rootDataDiffs === []
			&& $this->inexpressible === []
		;
	}

	/**
	 * The report for a human: a heading per non-empty category and its entries under it. Drift comes first
	 * and the notes last, because a reader who is being told the round trip is unsafe looks for the drift.
	 *
	 * @return list<string> lines without trailing newlines; empty when the round trip changed nothing at all
	 */
	public function describe(): array
	{
		$lines = [];

		foreach ($this->getCategories() as $heading => $entries)
		{
			if ($entries === [])
			{
				continue;
			}

			$lines[] = sprintf('%s (%d):', $heading, count($entries));
			foreach ($entries as $entry)
			{
				$lines[] = '  - ' . $entry;
			}
		}

		return $lines;
	}

	/**
	 * @return array<string, list<string>> heading => entries
	 */
	private function getCategories(): array
	{
		return [
			'Lost nodes' => $this->lostNodes,
			'Extra nodes' => $this->extraNodes,
			'Lost links' => $this->lostLinks,
			'Extra links' => $this->extraLinks,
			'Properties that differ' => $this->propertyDiffs,
			'Node descriptors that differ' => $this->descriptorDiffs,
			'Data of the template beside its nodes that differs' => $this->rootDataDiffs,
			'Constructs the source format cannot express' => $this->inexpressible,
			'Dangling links of the original, dropped by the reverse' => $this->danglingLinks,
			'Properties the build restores approximately' => $this->approximated,
		];
	}
}
