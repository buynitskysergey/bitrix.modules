<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;

/**
 * Shared visibility predicate for AI agent block catalogs.
 *
 * Both the REST catalog (AgentBlockCatalogService) and the Marta catalog
 * (BlockDescriptionService) must apply the same placement guards so that
 * agents are never offered a block they cannot build.
 *
 * Guard axes:
 *   1. Legacy composite containers - activities that declare TYPE='activity'
 *      with no NODE_TYPE but own children/branches. The agent draft converter
 *      cannot represent them as simple i0/o0 nodes; structure would be silently
 *      lost. Identified cheaply by CLASS field (no file load required).
 *      List shrinks as activities migrate to proper NODE_TYPE values.
 *   2. Incompatible NODE_TYPE - typed topologies (SERVICE, TOOL) that the
 *      converter cannot build. COMPLEX nodes are buildable via the rule-engine
 *      (ConvertRuleCommand assembles their sub-graph identically to the manual
 *      editor), and OPERATORS nodes - both branching (IfElseBranch, Approve,
 *      RequestInformationOptional) and loops (While, ForEach) - are buildable by
 *      the N-port draft converter; all of these are visible. Staged rollout of
 *      COMPLEX is handled by environment guards outside this predicate (the
 *      EXCLUDED option, the node-readiness option, and switchnode's dev-ENV node
 *      registration), never by re-adding COMPLEX here.
 *   3. Marta-hidden NODE_TYPE - FRAME. The frame overlay (EmptyBlockActivity,
 *      preset FRAME) is offered to the external REST agent (typed predicate) but
 *      stays hidden from Marta (raw predicate): Marta owns only logical graph
 *      construction, not the frame overlay. This is the single deliberate
 *      divergence between the two predicates; every other node type resolves
 *      identically on both.
 */
final class ActivityVisibilityFilter
{
	/**
	 * Legacy composite containers that the agent draft converter cannot build
	 * as simple i0/o0 nodes.
	 *
	 * @see isNodeCompatible(), isNodeCompatibleFromRaw()
	 */
	public const LEGACY_COMPOSITE_CLASS_BLACKLIST = [
		// Original 5 - root/branching containers
		'IfElseActivity',
		'ParallelActivity',
		'SequenceActivity',
		'StateMachineWorkflowActivity',
		'SequentialWorkflowActivity',
		// Multi-child event listeners (extends CBPCompositeActivity, NODE_TYPE=null,
		// TYPE=activity, no EXCLUDED)
		'RequestInformationActivity', // CBPRequestInformationActivity - collects $arActivities for approval branches
		'ListenActivity',             // CBPListenActivity - waits for one of N event branches
		'StateActivity',              // CBPStateActivity - sub-state container in StateMachineWorkflow
		// CBPSequenceActivity descendants with NODE_TYPE=null (no EXCLUDED)
		// EmptyBlockActivity is intentionally NOT here: it carries NODE_TYPE=FRAME and is gated by the
		// NODE_TYPE axis (visible to REST, hidden from Marta), not by this legacy-class blacklist.
		'StateInitializationActivity',   // CBPStateInitializationActivity - entry-point sequence for state
		'StateFinalizationActivity',     // CBPStateFinalizationActivity - exit-point sequence for state
		'EventDrivenActivity',           // CBPEventDrivenActivity - event-driven sequence with $arActivities presets
	];

	/**
	 * NODE_TYPE values that map to topologies the agent draft converter cannot build. Rejected on both
	 * predicates (REST and Marta).
	 *
	 * COMPLEX is intentionally absent: the rule-engine ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand})
	 * now assembles the complex sub-graph, so complex nodes are buildable and visible. Their staged
	 * rollout is enforced by environment guards (EXCLUDED option, node-readiness option, switchnode
	 * dev-ENV registration), not by this list.
	 *
	 * FRAME is intentionally absent too: it is not converter-unbuildable, it is a presentational overlay
	 * offered to REST but withheld from Marta - see {@see self::MARTA_HIDDEN_NODE_TYPES}.
	 */
	private const INCOMPATIBLE_NODE_TYPES = [
		ActivityNodeType::SERVICE->value,
		ActivityNodeType::TOOL->value,
	];

	/**
	 * NODE_TYPE values visible to the external REST agent (typed predicate) but hidden from Marta (raw
	 * predicate). FRAME (EmptyBlockActivity, preset FRAME) is the frame overlay: REST agents set its
	 * logical membership and styling, while Marta stays isolated from it. The single deliberate divergence
	 * between the two predicates.
	 */
	private const MARTA_HIDDEN_NODE_TYPES = [
		ActivityNodeType::FRAME->value,
	];

	/**
	 * Visibility predicate for the external REST agent, working on typed ActivityDescription objects
	 * (used by AgentBlockCatalogService which works with the new Activities API).
	 *
	 * Returns false for:
	 *   - legacy composite containers (CLASS blacklist)
	 *   - incompatible NODE_TYPE topologies (SERVICE/TOOL)
	 *
	 * FRAME passes: the frame overlay is offered to REST. Marta withholds it via
	 * {@see self::isNodeCompatibleFromRaw()}.
	 */
	public static function isNodeCompatible(ActivityDescription $d): bool
	{
		$class = $d->getClass();
		if (in_array($class, self::LEGACY_COMPOSITE_CLASS_BLACKLIST, true))
		{
			return false;
		}

		return !in_array($d->getNodeType(), self::INCOMPATIBLE_NODE_TYPES, true);
	}

	/**
	 * Visibility predicate for Marta, working on raw activity arrays
	 * (used by BlockDescriptionService which works with CBPRuntime::searchActivitiesByType).
	 *
	 * Returns false for:
	 *   - legacy composite containers (CLASS blacklist)
	 *   - incompatible NODE_TYPE topologies (SERVICE/TOOL)
	 *   - Marta-hidden NODE_TYPE topologies (FRAME) - visible to REST but withheld from Marta
	 */
	public static function isNodeCompatibleFromRaw(array $activity): bool
	{
		$class = (string)($activity['CLASS'] ?? '');
		if (in_array($class, self::LEGACY_COMPOSITE_CLASS_BLACKLIST, true))
		{
			return false;
		}

		$nodeType = (string)($activity['NODE_TYPE'] ?? '');
		if (in_array($nodeType, self::INCOMPATIBLE_NODE_TYPES, true))
		{
			return false;
		}

		return !in_array($nodeType, self::MARTA_HIDDEN_NODE_TYPES, true);
	}

	/**
	 * Returns true if the raw activity array carries a DEPRECATED=true flag.
	 */
	public static function isDeprecatedFromRaw(array $activity): bool
	{
		return ($activity['DEPRECATED'] ?? false) === true;
	}
}
