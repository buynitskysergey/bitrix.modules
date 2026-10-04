<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\App\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;

class ViewAllEmbeddingsRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		return $this->user->isAdmin();
	}
}
