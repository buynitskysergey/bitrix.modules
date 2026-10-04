<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Read;

final readonly class ThreadEntry
{
	private function __construct(
		public ?EmailActivity $activity,
		public bool $hidden,
	)
	{
	}

	public static function visible(EmailActivity $activity): self
	{
		return new self($activity, false);
	}

	public static function hidden(): self
	{
		return new self(null, true);
	}
}
