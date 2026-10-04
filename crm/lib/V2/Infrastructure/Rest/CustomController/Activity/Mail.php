<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Activity;

use Bitrix\Crm\Activity\Email\Read\ContentProvider;
use Bitrix\Crm\Activity\Email\Read\ThreadProvider;
use Bitrix\Crm\Activity\Email\Send;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Timeline\Activity\Email\AbstractEmail;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Timeline\Activity\Email\EmailActivityDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\Mail\GetContentRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\Mail\GetThreadRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\Mail\ReplyRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Timeline\Activity\Email\CrmActivityMailErrorMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Timeline\Activity\Email\CrmActivityMailResponseMapper;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

#[DtoType(EmailActivityDto::class)]
class Mail extends AbstractEmail
{
	public function getContentAction(GetContentRequest $request, CurrentUser $currentUser): ArrayResponse
	{
		$activityId = $this->validatePositiveId($request->activityId, 'activityId');
		$result = (new ContentProvider())->get($activityId, (int)$currentUser->getId());

		(new CrmActivityMailErrorMapper())->throwOnFailure($result);

		return new ArrayResponse(
			(new CrmActivityMailResponseMapper())->mapContent($result->getData()),
		);
	}

	public function getThreadAction(GetThreadRequest $request, CurrentUser $currentUser): ArrayResponse
	{
		$activityId = $this->validatePositiveId($request->activityId, 'activityId');
		$result = (new ThreadProvider())->get($activityId, (int)$currentUser->getId());

		(new CrmActivityMailErrorMapper())->throwOnFailure($result);

		return new ArrayResponse(
			(new CrmActivityMailResponseMapper())->mapThread($result->getData()),
		);
	}

	public function replyAction(ReplyRequest $request, CurrentUser $currentUser): ArrayResponse
	{
		$result = (new Send\ReplyService())->reply($this->createReplyRequest($request, $currentUser));

		(new CrmActivityMailErrorMapper())->throwOnFailure($result);

		return new ArrayResponse(
			(new CrmActivityMailResponseMapper())->mapSendResult($result->getData()),
		);
	}

	protected function createReplyRequest(ReplyRequest $request, CurrentUser $currentUser): Send\ReplyRequest
	{
		return new Send\ReplyRequest(
			userId: (int)$currentUser->getId(),
			parentActivityId: $this->validatePositiveId($request->activityId, 'activityId'),
			body: $this->validateBody($request->body),
			cc: $this->normalizeRecipientList($request->cc),
			bcc: $this->normalizeRecipientList($request->bcc),
			rawFrom: $this->normalizeOptionalString($request->from),
			senderId: $this->validateOptionalPositiveId($request->senderId, 'senderId'),
			mailboxId: $this->validateOptionalPositiveId($request->mailboxId, 'mailboxId'),
		);
	}
}
