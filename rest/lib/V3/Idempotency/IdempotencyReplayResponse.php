<?php

namespace Bitrix\Rest\V3\Idempotency;

use Bitrix\Rest\V3\Interaction\Response\Response;

/**
 * Wraps a cached idempotency payload so it can be substituted as the action
 * result on a replay. The base {@see Response::toArray()} uses reflection to
 * serialise public properties; we override it to return the verbatim cached
 * payload from {@see IdempotencyEntry}.
 */
final class IdempotencyReplayResponse extends Response
{
	public function __construct(
		private readonly array $payload,
		bool $showRawData = false,
		bool $showDebugInfo = true,
	)
	{
		$this->setShowRawData($showRawData)->setShowDebugInfo($showDebugInfo);
	}

	public function toArray(): array
	{
		return $this->payload;
	}
}
