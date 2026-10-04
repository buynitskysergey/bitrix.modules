<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

return new class extends NodesInstaller
{
	public function shouldInstall(): bool
	{
		return Loader::includeModule('imopenlines')
			&& class_exists(AiOpenLinesOperatorAgentFeature::class)
			&& ServiceLocator::getInstance()->get(AiOpenLinesOperatorAgentFeature::class)?->isAvailable()
		;
	}

	public function getModifiedTime(): int
	{
		return /*mtime*/1786526835/*mtime*/;
	}
};
