<?php

namespace Bitrix\Crm\Component\EntityList;

final class RowActionPermissions
{
	public static function shouldUseItemPermissions(bool $isInternal, bool $isExtendedInternal): bool
	{
		return !$isInternal || $isExtendedInternal;
	}
}
