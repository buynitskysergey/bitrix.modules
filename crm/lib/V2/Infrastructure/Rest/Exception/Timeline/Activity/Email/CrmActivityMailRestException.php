<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Timeline\Activity\Email;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

class CrmActivityMailRestException extends RestException implements SkipWriteToLogException
{
	public function __construct(
		private readonly string $restCode,
		private readonly string $publicMessage,
		?string $status = null,
	)
	{
		parent::__construct(status: $status);
	}

	public function getRegistryCode(): string
	{
		return $this->restCode;
	}

	public function output(?string $responseLanguage = null): array
	{
		return [
			'code' => $this->restCode,
			'message' => $this->publicMessage,
		];
	}

	protected function getLocalMessage(string $languageCode): string
	{
		return $this->publicMessage;
	}

	protected function getMessagePhraseCode(): string
	{
		return '';
	}
}
