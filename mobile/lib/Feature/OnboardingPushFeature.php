<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

class OnboardingPushFeature extends FeatureFlag
{
	private const FEATURE_CODE = 'should_send_onboarding';

	public function isEnabled(): bool
	{
		return (bool)Option::get('mobile', self::FEATURE_CODE, false);
	}

	public function enable(): void
	{
		Option::set('mobile', self::FEATURE_CODE, true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::FEATURE_CODE]);
	}
}
