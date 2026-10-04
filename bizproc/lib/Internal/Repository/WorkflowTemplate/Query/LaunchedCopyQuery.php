<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\Query;

use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * The only place that knows how a system AI-agent template is linked to its launched copies and
 * what counts as an active copy. Shared by the grid filter by system template and by the
 * exists-check behind the existing-runs warning, so the warning and the list it opens can never
 * disagree on the set of agents.
 *
 * Operates on a query built over WorkflowTemplateTable and knows nothing about the grid or about
 * the user's filter state: it takes a query and a list of system codes, nothing else.
 */
final class LaunchedCopyQuery
{
	private const ORIGIN_SETTING = 'ORIGIN_SETTING';

	/**
	 * Registers the origin-setting join on $query and returns the "launched copy of one of these
	 * system templates" predicate. The predicate is returned rather than applied because its two
	 * consumers combine it differently: the exists-check applies it as is, the grid filter ORs it
	 * with its own SYSTEM_CODE branch.
	 *
	 * The NAME predicate stays in the join ON clause: in the top-level WHERE it would make the
	 * LEFT JOIN null-rejecting and cut off system rows, which carry no origin setting at all.
	 *
	 * @param list<string> $systemCodes
	 */
	public static function joinOriginLink(Query $query, array $systemCodes): ConditionTree
	{
		$query->registerRuntimeField(
			self::ORIGIN_SETTING,
			new Reference(
				self::ORIGIN_SETTING,
				WorkflowTemplateSettingsTable::class,
				Join::on('this.ID', 'ref.TEMPLATE_ID')
					->where('ref.NAME', WorkflowTemplateSettingsTable::ORIGIN_SYSTEM_CODE),
			),
		);

		return Query::filter()->whereIn(self::ORIGIN_SETTING . '.VALUE', $systemCodes);
	}

	/**
	 * Narrows the query to active launched copies: a copy (SYSTEM_CODE IS NULL) that has been
	 * started (ACTIVATED_AT IS NOT NULL) and is switched on (ACTIVE = 'Y').
	 *
	 * ACTIVE is not a live-run check: the flag is written once, when the template is copied, and
	 * no product path clears it. It separates copies from system rows, which are installed
	 * inactive, and keeps this definition symmetric with the grid filter.
	 */
	public static function applyActive(Query $query): void
	{
		$query->where(self::buildActiveCondition());
	}

	/**
	 * Narrows the query to everything that is not an active launched copy: system rows, copies
	 * that were never started and copies that are switched off. Exact negation of applyActive(),
	 * so both branches of an activity filter always partition the same set.
	 */
	public static function applyInactive(Query $query): void
	{
		$query->where(self::buildActiveCondition()->negative());
	}

	private static function buildActiveCondition(): ConditionTree
	{
		return Query::filter()
			->whereNull('SYSTEM_CODE')
			->whereNotNull('ACTIVATED_AT')
			->where('ACTIVE', 'Y')
		;
	}
}
