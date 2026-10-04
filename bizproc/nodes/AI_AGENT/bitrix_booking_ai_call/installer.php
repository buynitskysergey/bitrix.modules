<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\Main\Loader;
use Bitrix\Booking\Provider\AiCallAvailabilityProvider;

return new class extends NodesInstaller
{
	public function shouldInstall(): bool
	{
		return (
			Loader::includeModule('booking')
			&& AiCallAvailabilityProvider::isAvailable()
		);
	}

	public function getModifiedTime(): int
	{
		return /*mtime*/1786526835/*mtime*/;
	}
};
