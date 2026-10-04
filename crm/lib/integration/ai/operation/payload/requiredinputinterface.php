<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload;

/**
 * Payload of a prompt that has no fallback for an incomplete input: the operation refuses the launch
 * instead of sending such a payload and names the gaps of the input in the log.
 */
interface RequiredInputInterface
{
	public function hasRequiredInput(): bool;

	/**
	 * @return string[] markers the prompt requires and the payload has not collected
	 */
	public function getMissingRequiredInput(): array;
}
