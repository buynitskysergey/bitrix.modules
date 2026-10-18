<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Exceptions;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The collection is too big to be answered as one tree.
 *
 * A refusal rather than a cut answer: a cut tree is indistinguishable from a whole one, so the caller
 * would draw it with branches silently missing. The phrase names the budget and stops there - REST V3
 * has no listing of documents to walk such a collection with.
 */
class TreeTooLargeException extends RestException implements SkipWriteToLogException
{
	public function __construct(
		private readonly int $maxNodes,
		?\Throwable $previous = null,
	)
	{
		parent::__construct($previous);
	}

	public function getRegistryCode(): string
	{
		return 'NOTE_TREE_TOO_LARGE';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'NOTE_REST_V3_EXCEPTION_TREETOOLARGEEXCEPTION_MSG';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return ['#MAX_NODES#' => (string)$this->maxNodes];
	}
}
