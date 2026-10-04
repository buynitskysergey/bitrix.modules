<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Structure;

use Bitrix\Bizproc\Public\Activity\Mixins\ChildFlowTraversal;
use Bitrix\Main\NotImplementedException;
use CBPActivityExecutionStatus;

/**
 * A node whose whole execution is the flow of its children: it starts the flow and stays open until the
 * queue has drained. The mechanics of the flow itself is shared with every other surface that runs children
 * ({@see ChildFlowTraversal}).
 *
 * The mixin is applied here even though the base activity already carries it ({@see \CBPActivity}): it is
 * what gives this family the public `onEvent` its listener interface asks for - the base class declares the
 * legacy protected one - and what restores the extension points of the traversal to their neutral defaults,
 * instead of the addressing the base class implements for the nodes of the unified settings panel. Every
 * node of this family answers both points itself
 * ({@see \Bitrix\Bizproc\Public\Activity\BaseComplexActivity}, {@see \CBPNodeWorkflowActivity}).
 *
 * @property-read string $Title
 * @property-read array $Links
 */
abstract class FlowDirectedActivity extends \CBPCompositeActivity implements \IBPActivityEventListener
{
	use ChildFlowTraversal;

	/**
	 * The two points of the traversal a member of this family answers itself, and the reason the answers are
	 * demanded here at all: the defaults the mixin brings are "nowhere" and "a dead end", so a member leaving
	 * a point to them is a node that runs no children and closes at once - silently.
	 *
	 * @var list<string>
	 */
	private const FLOW_DIRECTION_POINTS = ['getStartActivityNames', 'onDeadEndReached'];

	/**
	 * Classes of the family already known to answer both points: the demand is read once per class and not
	 * once per activity of a process.
	 *
	 * @var array<string, true>
	 */
	private static array $flowDirectionAnswered = [];

	public function __construct($name)
	{
		self::demandTheFlowDirectionIsAnswered(static::class);

		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_LINKS => [],
		];
	}

	/**
	 * The contract this class used to make with two `abstract` declarations. PHP refuses to re-declare an
	 * inherited concrete method as abstract, and the mixin above brings both points in concrete, so the demand
	 * is made at construction instead: a member of the family leaving either point unanswered is not built at
	 * all, the way the abstract declarations refused to compile it.
	 *
	 * @throws NotImplementedException
	 */
	private static function demandTheFlowDirectionIsAnswered(string $class): void
	{
		if (isset(self::$flowDirectionAnswered[$class]))
		{
			return;
		}

		foreach (self::FLOW_DIRECTION_POINTS as $point)
		{
			if ((new \ReflectionMethod($class, $point))->getDeclaringClass()->getName() === self::class)
			{
				throw new NotImplementedException(
					$class . ' must answer ' . $point . '(): the execution of a node of this family is the flow'
					. ' of its children, and the neutral default of the mixin runs none of them',
				);
			}
		}

		self::$flowDirectionAnswered[$class] = true;
	}

	public function execute(): int
	{
		return $this->startChildFlow()
			? CBPActivityExecutionStatus::Executing
			: CBPActivityExecutionStatus::Closed
		;
	}
}
