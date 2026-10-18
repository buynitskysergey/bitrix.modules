<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Exceptions;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

class SearchQueryTooShortException extends RestException implements SkipWriteToLogException
{
	public function __construct(
		private readonly int $minLength,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($previous);
	}

	public function getRegistryCode(): string
	{
		return 'NOTE_SEARCH_QUERY_TOO_SHORT';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'NOTE_REST_V3_EXCEPTION_SEARCHQUERYTOOSHORTEXCEPTION_MSG';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return ['#MIN_LENGTH#' => (string)$this->minLength];
	}
}
