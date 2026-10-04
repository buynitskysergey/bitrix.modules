<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Main\Localization\Loc;

final class RepeatSaleTranscription extends BaseFeature
{
	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_FEATURE_REPEAT_SALE_TRANSCRIPTION_NAME');
	}

	public function getCategory(): Category\RepeatSale
	{
		return Category\RepeatSale::getInstance();
	}

	protected function getOptionName(): string
	{
		return 'CRM_FEATURE_REPEAT_SALE_TRANSCRIPTION';
	}

	protected function getEnabledValue(): bool
	{
		return true;
	}
}
