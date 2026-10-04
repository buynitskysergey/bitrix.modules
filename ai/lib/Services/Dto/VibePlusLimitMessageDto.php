<?php declare(strict_types=1);

namespace Bitrix\AI\Services\Dto;

use Bitrix\AI\Enum\VibePlusLimitState;

class VibePlusLimitMessageDto
{
	public function __construct(
		public readonly VibePlusLimitState $state,
		public readonly string $msgForIm,
		public readonly string $sliderCode,
	)
	{
	}
}
