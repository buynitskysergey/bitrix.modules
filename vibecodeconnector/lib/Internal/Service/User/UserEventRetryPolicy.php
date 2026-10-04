<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\User;

final class UserEventRetryPolicy
{
	private const MINIMUM_RETRY_DELAY = 3600;

	public function getRetryDelay(mixed $retryAfterSeconds, int $now, int $deadline): int
	{
		$desiredDelay = self::MINIMUM_RETRY_DELAY;
		if (is_int($retryAfterSeconds) && $retryAfterSeconds > 0)
		{
			$desiredDelay = max($desiredDelay, $retryAfterSeconds);
		}

		return min($desiredDelay, max(1, $deadline - $now));
	}
}
