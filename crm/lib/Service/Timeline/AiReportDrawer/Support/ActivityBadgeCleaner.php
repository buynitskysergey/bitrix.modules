<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Crm\Badge\Badge;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\ItemIdentifier;

final class ActivityBadgeCleaner
{
	public function removeByOwner(ActivityContext $context): void
	{
		if ($context->currentUserId <= 0)
		{
			return;
		}

		$assignedById = Container::getInstance()
			->getFactory($context->request->ownerTypeId)
			?->getItem($context->request->ownerId)
			?->getAssignedById()
		;

		if ($context->currentUserId === $assignedById)
		{
			Badge::deleteByEntity(
				new ItemIdentifier($context->request->ownerTypeId, $context->request->ownerId),
				Badge::AI_FIELDS_FILLING_RESULT,
			);
		}
	}
}
