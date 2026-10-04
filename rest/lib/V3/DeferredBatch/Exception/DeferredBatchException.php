<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\DeferredBatch\Exception;

use Bitrix\Rest\V3\Exception\RestException;

/**
 * Thrown when deferred batch registration or retrieval fails with a user-facing message.
 */
class DeferredBatchException extends RestException
{
	private string $userMessage;
	private string $errorCode;

	public function __construct(string $message, string $code = 'ERROR', ?string $status = null)
	{
		$this->userMessage = $message;
		$this->errorCode = $code;
		parent::__construct(status: $status ?? \CRestServer::STATUS_WRONG_REQUEST);
	}

	protected function getMessagePhraseCode(): string
	{
		// Not used — message is set directly.
		return '';
	}

	protected function getLocalMessage(string $languageCode): string
	{
		return $this->userMessage;
	}

	public function output(?string $responseLanguage = null): array
	{
		return [
			'code'    => $this->errorCode,
			'message' => $this->userMessage,
		];
	}
}

