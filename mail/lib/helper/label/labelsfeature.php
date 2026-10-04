<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Label;

use Bitrix\Main\Config\Option;

final class LabelsFeature
{
	public const ONBOARDING_BOUNDARY_ID_OPTION = 'user_labels_onboarding_boundary_id';

	private const OPTION_NAME = 'user_labels_enabled';

	public static function isEnabled(): bool
	{
		return Option::get('mail', self::OPTION_NAME, 'N') === 'Y';
	}
}
