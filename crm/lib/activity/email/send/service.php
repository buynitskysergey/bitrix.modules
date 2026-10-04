<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Send;

use Bitrix\Crm\Activity\Email\Outgoing\MessageSender;
use Bitrix\Crm\Activity\Email\Outgoing\SendRequest;
use Bitrix\Crm\Activity\Email\EntityType;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Mail\Helper\Message as MailHelperMessage;
use Bitrix\Main\Loader;
use Bitrix\Main\Result as MainResult;
use CCrmActivity;
use CCrmOwnerType;

final class Service
{
	public function __construct(private readonly MessageSender $sender = new MessageSender())
	{
	}

	public function send(Request $request): MainResult
	{
		if (!CCrmOwnerType::IsDefined($request->entityTypeId))
		{
			return Result::fail('Unknown entityTypeId.');
		}
		if (!EntityType::isSupported($request->entityTypeId))
		{
			return Result::fail('Unsupported entityTypeId.');
		}

		if (!Loader::includeModule('mail'))
		{
			return Result::fail('The "mail" module is not available.');
		}

		$factory = Container::getInstance()->getFactory($request->entityTypeId);
		if ($factory === null || !$factory->getItem($request->entityId, ['ID']))
		{
			return Result::fail('CRM entity not found.');
		}

		$userPerms = Container::getInstance()->getUserPermissions($request->userId)->getCrmPermissions();
		if (!CCrmActivity::CheckUpdatePermission($request->entityTypeId, $request->entityId, $userPerms))
		{
			return Result::fail('Access denied to the entity.');
		}

		return $this->sender->send(new SendRequest(
			userId: $request->userId,
			mainOwnerTypeId: $request->entityTypeId,
			mainOwnerId: $request->entityId,
			bindings: [
				['OWNER_TYPE_ID' => $request->entityTypeId, 'OWNER_ID' => $request->entityId],
			],
			parentActivityId: null,
			subject: MailHelperMessage::getOutgoingSubject(
				$request->subject,
				$request->body,
				'(no subject)',
			),
			body: $request->body,
			to: $request->to,
			cc: $request->cc,
			bcc: $request->bcc,
			rawFrom: $request->rawFrom,
			senderId: $request->senderId,
			mailboxId: $request->mailboxId,
		));
	}
}
