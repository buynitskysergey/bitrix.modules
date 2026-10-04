<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Control;

use Bitrix\UI\AccessRights\V2\Control\MultiVariables as BaseMultiVariables;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem;

/**
 * The stock multivariables control keeps `allSelectedCode` but does not push it onto the right item.
 * DTO-01 requires it on every multivariables right (the «select all» sentinel), so it is emitted here.
 */
final class MultiVariables extends BaseMultiVariables
{
	public function configureRightItem(RightItem $rightItem): void
	{
		parent::configureRightItem($rightItem);

		$rightItem->setAllSelectedCode($this->allSelectedCode);
	}
}
