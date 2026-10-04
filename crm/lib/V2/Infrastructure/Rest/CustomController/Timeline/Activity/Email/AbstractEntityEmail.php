<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Timeline\Activity\Email;

use Bitrix\Crm\Activity\Email\Read\SearchProvider;
use Bitrix\Crm\Activity\Email\Send;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Timeline\Activity\Email\EmailActivityDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Timeline\Activity\Email\ListRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Timeline\Activity\Email\SendRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Timeline\Activity\Email\CrmActivityMailErrorMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Timeline\Activity\Email\CrmActivityMailResponseMapper;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use CCrmActivityDirection;

#[DtoType(EmailActivityDto::class)]
abstract class AbstractEntityEmail extends AbstractEmail
{
	final public function listAction(
		ListRequest $request,
		CurrentUser $currentUser,
		EntityType $entityType,
	): ArrayResponse
	{
		$direction = match ($request->isIncoming)
		{
			true => CCrmActivityDirection::Incoming,
			false => CCrmActivityDirection::Outgoing,
			null => null,
		};

		$result = (new SearchProvider())->search(
			userId: (int)$currentUser->getId(),
			entityTypeId: $entityType->getId(),
			entityId: $this->validatePositiveId($request->id, 'id'),
			direction: $direction,
			limit: $request->limit,
			offset: $request->offset,
			countTotal: false,
		);

		(new CrmActivityMailErrorMapper())->throwOnFailure($result);

		return new ArrayResponse(
			(new CrmActivityMailResponseMapper())->mapList($result->getData()),
		);
	}

	final public function sendAction(
		SendRequest $request,
		CurrentUser $currentUser,
		EntityType $entityType,
	): ArrayResponse
	{
		$result = (new Send\Service())->send($this->createSendRequest($request, $currentUser, $entityType));

		(new CrmActivityMailErrorMapper())->throwOnFailure($result);

		return new ArrayResponse(
			(new CrmActivityMailResponseMapper())->mapSendResult($result->getData()),
		);
	}

	protected function createSendRequest(
		SendRequest $request,
		CurrentUser $currentUser,
		EntityType $entityType,
	): Send\Request
	{
		return new Send\Request(
			userId: (int)$currentUser->getId(),
			entityTypeId: $entityType->getId(),
			entityId: $this->validatePositiveId($request->id, 'id'),
			to: $this->validateRecipients($request->to, 'to'),
			cc: $this->normalizeRecipientList($request->cc),
			bcc: $this->normalizeRecipientList($request->bcc),
			subject: (string)($request->subject ?? ''),
			body: $this->validateBody($request->body),
			rawFrom: $this->normalizeOptionalString($request->from),
			senderId: $this->validateOptionalPositiveId($request->senderId, 'senderId'),
			mailboxId: $this->validateOptionalPositiveId($request->mailboxId, 'mailboxId'),
		);
	}
}
