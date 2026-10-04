<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Rule\Factory;

use Bitrix\Bizproc\Internal\Access\Rule\BaseRule;
use Bitrix\Main\Access\AccessibleController;
use Bitrix\Main\Access\Rule\Factory\RuleControllerFactory;

/**
 * The bizproc template ACL matrix is flat: every action resolves to the single {@see BaseRule}.
 * The class is pinned (not derived from the controller namespace) so that subclasses — including test
 * doubles living in other namespaces — reuse the real rule.
 */
final class RuleFactory extends RuleControllerFactory
{
	protected function getClassName(string $action, AccessibleController $controller): ?string
	{
		return BaseRule::class;
	}
}
