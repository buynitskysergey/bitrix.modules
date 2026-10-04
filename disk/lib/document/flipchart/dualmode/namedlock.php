<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

/**
 * A portal-wide named lock.
 */
interface NamedLock
{
	public function acquire(string $name): bool;

	public function release(string $name): void;
}
