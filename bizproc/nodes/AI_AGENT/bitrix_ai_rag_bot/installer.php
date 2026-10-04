<?php

declare(strict_types=1);

use Bitrix\Bizproc\Internal\Integration\Rag\Service\RagService;
use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\Main\DI\ServiceLocator;

return new class extends NodesInstaller
{
	public function shouldInstall(): bool
	{
		return ServiceLocator::getInstance()
			->get(RagService::class)
			->isAvailable()
		;
	}

	public function getModifiedTime(): int
	{
		return /*mtime*/1788775200/*mtime*/;
	}
};
