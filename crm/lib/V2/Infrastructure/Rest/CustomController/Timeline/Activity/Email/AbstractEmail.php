<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Timeline\Activity\Email;

use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Timeline\Activity\Email\CrmActivityMailErrorMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;

abstract class AbstractEmail extends AbstractController
{
	protected function validatePositiveId(?int $value, string $fieldName): int
	{
		if ($value === null || $value <= 0)
		{
			CrmActivityMailErrorMapper::throwInvalidRequest(
				$fieldName . ' must be a positive integer.',
			);
		}

		return $value;
	}

	protected function validateOptionalPositiveId(?int $value, string $fieldName): ?int
	{
		return $value === null ? null : $this->validatePositiveId($value, $fieldName);
	}

	protected function validateBody(?string $body): string
	{
		if ($body === null || trim($body) === '')
		{
			CrmActivityMailErrorMapper::throwInvalidRequest('body must be a non-empty string.');
		}

		return $body;
	}

	protected function validateRecipients(?array $recipients, string $fieldName): array
	{
		$recipients = $this->normalizeRecipientList($recipients);
		if (empty($recipients))
		{
			CrmActivityMailErrorMapper::throwInvalidRequest(
				$fieldName . ' must contain at least one email address.',
			);
		}

		return $recipients;
	}

	protected function normalizeRecipientList(?array $value): array
	{
		return $value === null ? [] : array_values($value);
	}

	protected function normalizeOptionalString(?string $value): ?string
	{
		$value = trim((string)$value);

		return $value !== '' ? $value : null;
	}
}
