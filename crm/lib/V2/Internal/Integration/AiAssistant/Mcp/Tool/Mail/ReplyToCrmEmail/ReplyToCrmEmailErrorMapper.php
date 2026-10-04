<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\ReplyToCrmEmail;

use Bitrix\Main\Result;

final class ReplyToCrmEmailErrorMapper
{
	private const RECIPIENTS_NOT_FOUND = 'Cannot determine reply recipients from the parent activity.';
	private const SEND_HINT = 'Use send_crm_email with explicit "to" instead.';

	public function map(Result $result): string
	{
		$message = implode("\n", $result->getErrorMessages());
		if (
			str_contains($message, self::RECIPIENTS_NOT_FOUND)
			&& !str_contains($message, 'send_crm_email')
		)
		{
			$message .= ' ' . self::SEND_HINT;
		}

		return $message;
	}
}
