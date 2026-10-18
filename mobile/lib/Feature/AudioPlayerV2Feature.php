<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class AudioPlayerV2Feature extends FeatureFlag
{
	private const OPTION_NAME = 'global_audio_player';

	public function isEnabled(): bool
	{
		return (bool)Option::get('mobile', self::OPTION_NAME, false);
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_NAME, true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_NAME]);
	}
}
