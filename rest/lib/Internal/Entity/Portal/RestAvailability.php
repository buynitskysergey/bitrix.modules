<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Portal;

use Bitrix\Rest\Engine\Access;

class RestAvailability
{
	public function toArray(): array
	{
		return ['isAvailable' => Access::isFeatureEnabled()];
	}
}
