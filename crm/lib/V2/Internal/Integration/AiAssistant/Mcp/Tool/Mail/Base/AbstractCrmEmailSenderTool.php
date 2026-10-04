<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\Base;

use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;

abstract class AbstractCrmEmailSenderTool extends AbstractTool
{
	/**
	 * Keeps DTO input shape defensive without validating or dropping bad addresses.
	 *
	 * @return list<mixed>
	 */
	final protected function asRecipientList(mixed $value): array
	{
		if ($value === null)
		{
			return [];
		}

		return is_array($value) ? array_values($value) : [$value];
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}
}
