<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\Slider;

use Bitrix\Main\Application;

class AutomationSliderLock
{
	public function lock(string $name, int $timeout): bool
	{
		return Application::getConnection()->lock($name, $timeout);
	}

	public function unlock(string $name): void
	{
		Application::getConnection()->unlock($name);
	}
}
