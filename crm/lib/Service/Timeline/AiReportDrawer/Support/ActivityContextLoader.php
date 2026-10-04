<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Crm\Activity\BindIdentifier;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Controller\Validator;
use Bitrix\Crm\Controller\Validator\Validation;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\DrawerRequest;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ActivityContextLoader
{
	public function __construct(
		private readonly ClientDataProvider $clientDataProvider,
		private readonly ResponsibleDataProvider $responsibleDataProvider,
	)
	{
	}

	public function load(DrawerRequest $request, int $currentUserId = 0): Result
	{
		$result = new Result();
		$itemIdentifier = ItemIdentifier::createByParams($request->ownerTypeId, $request->ownerId);
		if ($itemIdentifier === null)
		{
			return $result->addError(ErrorCode::getOwnerNotFoundError());
		}

		$binding = new BindIdentifier($itemIdentifier, $request->activityId);
		$validation = (new Validation())
			->validate($binding->getActivityId(), [new Validator\Activity\ActivityExists()])
			->validate($binding, [new Validator\Activity\BindingExists()])
			->validate($itemIdentifier, [new Validator\Activity\ReadPermission()])
		;

		if (!$validation->isSuccess())
		{
			return $result->addErrors($validation->getErrors());
		}

		$activity = Container::getInstance()->getActivityBroker()->getById($request->activityId);
		if (!is_array($activity))
		{
			return $result->addError(new Error('Activity not found'));
		}

		return $result->setData([
			'context' => new ActivityContext(
				request: $request,
				activity: $activity,
				clientData: $this->clientDataProvider->getByActivityId($request->activityId),
				responsibleData: $this->responsibleDataProvider->getById((int)($activity['RESPONSIBLE_ID'] ?? 0)),
				currentUserId: $currentUserId,
			),
		]);
	}
}
