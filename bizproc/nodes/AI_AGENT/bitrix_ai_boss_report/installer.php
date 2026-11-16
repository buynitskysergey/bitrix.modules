<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\Bizproc\Public\Feature\AiAgent\BossReportAgentFlag;

return new class extends NodesInstaller
{
	public function shouldInstall(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(BossReportAgentFlag::class);
	}

	public function getModifiedTime(): int
	{
		return /*mtime*/1786526835/*mtime*/;
	}
};
