<?php

namespace Bitrix\Mobile\Internal\Onboarding\Dto;

readonly class LastPushInfo
{
	public function __construct(
		public string $type,
		public int $timestamp,
	) {}
}
