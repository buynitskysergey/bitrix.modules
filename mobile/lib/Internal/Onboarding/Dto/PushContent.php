<?php

namespace Bitrix\Mobile\Internal\Onboarding\Dto;

use Bitrix\Mobile\Internal\Onboarding\PushType;

readonly class PushContent
{
	public function __construct(
		public string $title,
		public string $text,
		public PushType $type,
	) {}
}
