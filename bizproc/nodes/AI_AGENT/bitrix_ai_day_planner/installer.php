<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

return new class extends NodesInstaller
{
	private ?string $copilotName = null;

	public function shouldInstall(): bool
	{
		return Option::get('bizproc', 'bitrix_ai_day_plan_available', 'N') === 'Y';
	}

	public function getModifiedTime(): int
	{
		return /*mtime*/1786526835/*mtime*/;
	}

	public function getMessageReplacements(): array
	{
		return [
			'BITRIX_AI_DAY_PLANNER_NODATA_PLAN' => [
				'#COPILOT_NAME#' => $this->getCopilotName(),
			],
		];
	}

	private function getCopilotName(): string
	{
		if ($this->copilotName === null)
		{
			$this->copilotName = Loader::includeModule('ui') && class_exists(CopilotNameService::class)
				? (new CopilotNameService())->getCopilotName()
				: 'BitrixGPT'
			;
		}

		return $this->copilotName;
	}
};
