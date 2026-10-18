<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\BaseObject;

interface AccessCheckHandler
{
	public function check(BaseObject $baseObject): UnifiedLinkAccessLevel;

	public function checkCollection(array $baseObjects): array;

	public function checkAll(BaseObject $baseObject): UnifiedLinkAccessResult;

	public function append(AccessCheckHandler $handler): AccessCheckHandler;
}
