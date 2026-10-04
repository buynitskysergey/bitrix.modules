<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\Main\Config\Option;

return new class extends NodesInstaller
{
	public function shouldInstall(): bool
	{
		return Option::get('bizproc', 'bitrix_ai_boss_report_available', 'N') === 'Y';
	}

	public function getModifiedTime(): int
	{
		return /*mtime*/1786526835/*mtime*/;
	}
};
