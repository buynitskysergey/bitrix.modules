<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Main\Application;

final class DefaultNamedLock implements NamedLock
{
	public function acquire(string $name): bool
	{
		return Application::getConnection()->lock($name);
	}

	public function release(string $name): void
	{
		Application::getConnection()->unlock($name);
	}
}
