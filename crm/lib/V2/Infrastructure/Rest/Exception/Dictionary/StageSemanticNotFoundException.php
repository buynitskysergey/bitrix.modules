<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Dictionary;

use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

final class StageSemanticNotFoundException extends RestException implements SkipWriteToLogException
{
	public function __construct(private readonly string $id)
	{
		parent::__construct();
	}

	protected function getClassWithPhrase(): string
	{
		return EntityNotFoundException::class;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_ENTITYNOTFOUNDEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return ['#ID#' => $this->id];
	}
}
