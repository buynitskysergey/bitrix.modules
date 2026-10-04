<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Storage;

interface RemainingDiskSpaceProviderInterface
{
	/**
	 * @return int|null free disk bytes left for the portal, null when the limit is unknown
	 */
	public function getRemainingBytes(): ?int;
}
