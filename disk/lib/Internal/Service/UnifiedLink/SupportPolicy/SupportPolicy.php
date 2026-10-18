<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\SupportPolicy;

use Bitrix\Disk\BaseObject;

interface SupportPolicy
{
	/**
	 * Checks if the given object has unified link support.
	 *
	 * @param BaseObject $baseObject
	 * @return bool
	 */
	public function supports(BaseObject $baseObject): bool;
}
