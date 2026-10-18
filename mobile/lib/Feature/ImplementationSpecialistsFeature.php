<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class ImplementationSpecialistsFeature extends FeatureFlag
{
	public function isEnabled(): bool
	{
		return Option::get('mobile', 'feature_implementation_specialists_enabled', 'N') === 'Y';
	}

	public function enable(): void
	{
		Option::set('mobile', 'feature_implementation_specialists_enabled', 'Y');
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => 'feature_implementation_specialists_enabled']);
	}
}
