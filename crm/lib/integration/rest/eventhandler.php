<?php

namespace Bitrix\Crm\Integration\Rest;

use Bitrix\Crm\RepeatSale\Segment\SubscriptionSegmentService;
use Bitrix\Crm\Timeline\Entity\Repository\RestAppLayoutBlocksRepository;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\EO_App;

class EventHandler
{
	/**
	 * Handles rest:onSubscriptionRenew — the internal event published when the paid subscription
	 * date changes and the subscription is available again. The event carries no payload, so the
	 * subscription state is not read from it; the positive transition is delegated to the domain
	 * service, which re-reads the current state on its own. The service is idempotent against
	 * repeated firings thanks to the auto-disable memory and the "seen" marker.
	 *
	 * @see \Bitrix\Rest\Marketplace\Client::onChangeSubscriptionDate() the event source
	 */
	public static function onSubscriptionRenew(Event $event): void
	{
		(new SubscriptionSegmentService())->onPositiveTransition();
	}

	public static function onRestAppDelete(array $app): void
	{
		if (
			empty($app['APP_ID'])
			|| empty($app['CLEAN'])
			|| !Loader::includeModule('rest')
		)
		{
			return;
		}

		$restApp = self::getRestAppById((int)$app['APP_ID']);
		if ($restApp === null)
		{
			return;
		}

		self::deleteRestAppLayoutBlocks($restApp->getClientId());
	}

	private static function getRestAppById(int $id): EO_App|null
	{
		return AppTable::query()
			->setSelect(['*'])
			->where('ID', $id)
			->fetchObject()
		;
	}

	private static function deleteRestAppLayoutBlocks(string $clientId): void
	{
		(new RestAppLayoutBlocksRepository())->deleteByClientId($clientId);
	}

	public static function onUserFieldPlacementPrepareParams(Event $event): void
	{
		$params = $event->getParameters();
		$arUserField = $params[0];
		$placementOptions = &$params[1];

		if (!str_starts_with($arUserField['ENTITY_ID'], 'CRM_'))
		{
			return;
		}

		$entityTypeId = \CCrmOwnerType::ResolveIDByUFEntityID($arUserField['ENTITY_ID']);
		if ($entityTypeId === \CCrmOwnerType::Undefined)
		{
			return;
		}

		$placementOptions['ENTITY_DATA'] = [
			'entityTypeId' => $entityTypeId,
			'entityId' => $arUserField['ENTITY_VALUE_ID'],
			'module' => 'crm',
		];
	}
}
