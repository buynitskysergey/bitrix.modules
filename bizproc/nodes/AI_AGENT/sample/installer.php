<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;

return new class extends NodesInstaller
{
	public function shouldInstall(): bool
	{
		return defined('Bitrix\Bizproc\Dev\ENV');
	}

	public function getModifiedTime(): int
	{
		return /*mtime*/1786526835/*mtime*/;
	}
};
