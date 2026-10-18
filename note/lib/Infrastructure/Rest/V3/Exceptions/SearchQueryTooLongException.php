<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Exceptions;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

class SearchQueryTooLongException extends RestException implements SkipWriteToLogException
{
	public function __construct(
		private readonly int $maxLength,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($previous);
	}

	public function getRegistryCode(): string
	{
		return 'NOTE_SEARCH_QUERY_TOO_LONG';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'NOTE_REST_V3_EXCEPTION_SEARCHQUERYTOOLONGEXCEPTION_MSG';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return ['#MAX_LENGTH#' => (string)$this->maxLength];
	}
}
