<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Crm\Service\Container;

final class ClientDataProvider
{
	public function getByActivityId(int $activityId): ?array
	{
		$commInfo = \CAllCrmActivity::PrepareCommunicationInfos([$activityId]);

		if (empty($commInfo) || !isset($commInfo[$activityId]))
		{
			return null;
		}

		$entityTypeId = $commInfo[$activityId]['ENTITY_TYPE_ID'] ?? null;
		$entityId = $commInfo[$activityId]['ENTITY_ID'] ?? null;
		if (!$entityTypeId || !$entityId)
		{
			return null;
		}

		$canRead = Container::getInstance()
			->getUserPermissions()
			->item()
			->canRead($entityTypeId, $entityId)
		;

		if (!$canRead)
		{
			return [
				'isHidden' => true,
			];
		}

		return [
			'clientEntityTypeId' => $entityTypeId,
			'clientId' => $entityId,
			'clientDetailsUrl' => $commInfo[$activityId]['SHOW_URL'] ?? null,
		];
	}
}
