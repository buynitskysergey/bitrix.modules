<?php

namespace Bitrix\Crm\Copilot\AiQueueBuffer\Provider;

use Bitrix\Main\Result;

interface QueueBufferProviderInterface
{
	public static function getId(): int;

	/**
	 * @param bool $isFinalAttempt the buffer is on the last retry before dropping the item; a
	 *        provider that gates on a pending async prerequisite must proceed with what is ready
	 *        instead of deferring again (partial success)
	 */
	public function process(?array $data = null, bool $isFinalAttempt = false): Result;
}
