<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Trait;

use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseSettingsExpressionDto;

trait NodeActionResolver
{
	/**
	 * Decide whether a BASE_SETTINGS construction is bound to a node-action (node-action proxy mode)
	 * instead of being host-merged.
	 *
	 * A construction is treated as a node-action proxy when it carries an explicit actionId
	 * (primary signal, set by the editor/preset) OR when its backing activity type resolves to a
	 * node-action activity (defensive fallback for direct payloads). The node-level catalog
	 * membership of that action is enforced separately by ValidateSingleRuleCommand.
	 *
	 * @param BaseSettingsExpressionDto $expression
	 * @param string $backingActivityType Backing type resolved by the caller from its own payload
	 *   stage (activityData.Type after normalisation, rawActivityData.activityType before it).
	 */
	protected function isNodeActionBaseSettings(
		BaseSettingsExpressionDto $expression,
		string $backingActivityType,
	): bool
	{
		if ($expression->actionId !== null && $expression->actionId !== '')
		{
			return true;
		}

		return $this->isNodeActionBackingType($backingActivityType);
	}

	protected function isNodeActionBackingType(string $activityType): bool
	{
		if ($activityType === '')
		{
			return false;
		}

		$description = Container::instance()->getActivitySearcherService()->searchByCode($activityType);
		if ($description === null)
		{
			return false;
		}

		return in_array(ActivityType::NODE_ACTION->value, $description->getType(), true);
	}
}
