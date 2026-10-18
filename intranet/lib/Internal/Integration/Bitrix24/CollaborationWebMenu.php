<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Config\Feature\CollaborationWebMenuFlag;
use Bitrix\Intranet\Config\Feature\CollaborationWebMenuFlag as IntranetCollaborationWebMenuFlag;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Loader;

class CollaborationWebMenu
{
	public static function isEnabled(): bool
	{
		if (!class_exists(Feature::class) || !is_callable([Feature::class, 'isEnabled']))
		{
			return false;
		}

		if (Loader::includeModule('bitrix24'))
		{
			return class_exists(CollaborationWebMenuFlag::class)
				&& Feature::isEnabled(CollaborationWebMenuFlag::class);
		}

		return class_exists(IntranetCollaborationWebMenuFlag::class)
			&& Feature::isEnabled(IntranetCollaborationWebMenuFlag::class);
	}
}
