<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

final class PurchasesFeature extends FeatureFlag
{
	public const OPTION_NAME = 'purchases_card_enabled';

	public function isEnabled(): bool
	{
		return Option::get('mobile', self::OPTION_NAME, 'N') === 'Y';
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_NAME, 'Y');
	}

	public function disable(): void
	{
		Option::set('mobile', self::OPTION_NAME, 'N');
	}
}
