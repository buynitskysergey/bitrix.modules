<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Intranet;

use Bitrix\Intranet\Util;
use Bitrix\Main\Loader;

class IntranetGate
{
	public function isIntranet(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->fetchIsIntranet($userId);
	}

	protected function fetchIsIntranet(int $userId): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		return Util::isIntranetUser($userId);
	}
}
