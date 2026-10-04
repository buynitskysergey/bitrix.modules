<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Exception;

use Bitrix\Main\SystemException;

class CustomTemplateInvariantException extends SystemException
{
	public static function missingField(?int $templateId, string $field): self
	{
		return new self(
			'CustomTemplate entity #' . (string)($templateId ?? 0)
			. ' has no ' . $field
			. ' - repository invariant violated'
		);
	}
}
