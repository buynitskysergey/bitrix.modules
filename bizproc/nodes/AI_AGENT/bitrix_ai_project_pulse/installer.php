<?php

declare(strict_types=1);

use Bitrix\Bizproc\Internal\AiAgent\Scenario\ProjectPulse\LaunchStepper;
use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\Bizproc\Public\Feature\AiAgent\ProjectPulseAgentFlag;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Feature;

return new class() extends NodesInstaller
{
	public function isNewProjectsAvailable(): bool
	{
		return
			Loader::includeModule('socialnetwork')
			&& class_exists(Feature::class)
			&& Feature::isNewProjectsOn();
	}

	public function shouldInstall(): bool
	{
		return
			\Bitrix\Main\Config\Feature::isEnabled(ProjectPulseAgentFlag::class)
			&& $this->isNewProjectsAvailable();
	}

	public function getModifiedTime(): int
	{
		return /*mtime*/1786526835/*mtime*/;
	}
};
