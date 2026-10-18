<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Exceptions;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * A refusal of its own rather than a plain "no access": the caller may edit both ends of the move, but
 * the destination is shared with a subtree audience, so landing there would hand that audience access
 * to everything being moved. Telling this apart from an ordinary refusal is what lets a client explain
 * that the move needs a moderator instead of repeating a request that will never pass.
 */
class MoveAccessEscalationException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	public function getRegistryCode(): string
	{
		return 'NOTE_MOVE_ACCESS_ESCALATION';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'NOTE_REST_V3_EXCEPTION_MOVEACCESSESCALATIONEXCEPTION_MSG';
	}
}
