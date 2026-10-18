<?php

namespace Bitrix\TimemanMobile\Infrastructure\Controllers\Trait;

use Bitrix\Mobile\Config\Feature;
use Bitrix\TimemanMobile\Public\Features\WorkReportsWithoutAiFeature;
use Bitrix\TimemanMobile\Public\Providers\SettingsProvider;

trait ReportSettingsPayloadTrait
{
	protected function getReportSettingsPayload(): array
	{
		if (Feature::isEnabled(WorkReportsWithoutAiFeature::class))
		{
			return [
				'isReportsSettingTurnedOn' => true,
				'isReportGenerationAvailable' => false,
				'hasAiReportAccess' => false,
			];
		}

		$provider = new SettingsProvider();

		return [
			'isReportsSettingTurnedOn' => $provider->isReportsSettingTurnedOn(),
			'isReportGenerationAvailable' => $provider->isReportGenerationAvailable(),
			'hasAiReportAccess' => $provider->hasAiReportAccess(),
		];
	}
}
