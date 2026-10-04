<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\Bizproc\Public\Feature\AiAgent\CoachAgentFlag;

return new class() extends NodesInstaller
{
	public function shouldInstall(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(CoachAgentFlag::class);
	}

	public function getModifiedTime(): int
	{
		return /*mtime*/1786526835/*mtime*/;
	}
};
