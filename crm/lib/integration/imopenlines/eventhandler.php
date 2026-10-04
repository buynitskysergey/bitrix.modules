<?php

namespace Bitrix\Crm\Integration\ImOpenLines;

use Bitrix\Crm\Activity\Provider\ProviderManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\ActivityController;
use Bitrix\Crm\Timeline\LogMessageController;
use Bitrix\Crm\Timeline\LogMessageType;
use Bitrix\ImOpenLines\Session;
use Bitrix\Main\Event;
use CCrmActivity;
use CCrmOwnerType;

class EventHandler
{
	/**
	 * Event handler when CRM entities created in open lines.
	 *
	 * @param Event $event
	 */
	public static function OnImOpenLineRegisteredInCrm(Event $event): void
	{
		$data = $event->getParameters();
		if (empty($data) || empty($data['BINDINGS']))
		{
			return;
		}

		$createdEntities = array_values(
			array_filter(
				$data['BINDINGS'],
				static fn($row) => in_array((int)$row['OWNER_TYPE_ID'], [CCrmOwnerType::Lead, CCrmOwnerType::Deal], true)
			)
		);
		$baseEntities = array_values(
			array_filter(
				$data['BINDINGS'],
				static fn($row) => in_array((int)$row['OWNER_TYPE_ID'], [CCrmOwnerType::Contact, CCrmOwnerType::Company], true)
			)
		);

		if (empty($createdEntities))
		{
			return;
		}

		$input = [
			"ENTITY_TYPE_ID" => $createdEntities[0]['OWNER_TYPE_ID'],
			"ENTITY_ID" => $createdEntities[0]['OWNER_ID'],
		];

		if (!empty($baseEntities))
		{
			$input['BASE_ENTITY_TYPE_ID'] = $baseEntities[0]['OWNER_TYPE_ID'];
			$input['BASE_ENTITY_ID'] = $baseEntities[0]['OWNER_ID'];

			if (isset($data['PROVIDER_PARAMS']['USER_CODE']))
			{
				$input['BASE_SOURCE'] = $data['PROVIDER_PARAMS']['USER_CODE'];
			}
		}

		LogMessageController::getInstance()->onCreate(
			$input,
			LogMessageType::OPEN_LINE_INCOMING,
			$data['AUTHOR_ID'] ?? null
		);
	}

	/**
	 * Event handler when close chat.
	 *
	 * @param Event $event
	 */
	public static function OnChatFinish(Event $event): void
	{
		$parameters = $event->getParameters();
		$session = $parameters['RUNTIME_SESSION'];
		if (!$session instanceof Session)
		{
			return;
		}

		$activityId = (int)($session->getData('CRM_ACTIVITY_ID') ?? 0);
		if ($activityId <= 0)
		{
			return;
		}

		$activity = CCrmActivity::GetByID($activityId, false);
		if (!$activity)
		{
			return;
		}

		// Chat::finish() may receive an already completed CRM activity. If the session then waits
		// for rating, setSessionClosed() is not reached and stale badges must be synced here.
		if (isset($activity['COMPLETED']) && $activity['COMPLETED'] === 'Y')
		{
			ProviderManager::syncBadgesOnActivityUpdate($activityId, $activity);

			return;
		}

		$bindings = CCrmActivity::GetBindings($activityId);
		if (!$bindings)
		{
			return;
		}

		$userPermissions = Container::getInstance()->getUserPermissions()->getCrmPermissions();
		$isAtLeastOnePermissionEnabled = false;
		foreach ($bindings as $binding)
		{
			if (
				CCrmActivity::CheckCompletePermission(
					$binding['OWNER_TYPE_ID'],
					$binding['OWNER_ID'],
					$userPermissions,
					['FIELDS' => $activity]
				)
			)
			{
				$isAtLeastOnePermissionEnabled = true;

				break;
			}
		}

		if ($isAtLeastOnePermissionEnabled)
		{
			$operatorId = (int)($session->getData('OPERATOR_ID') ?? 0);
			$completeOptions = [
				'REGISTER_SONET_EVENT' => true,
				'SKIP_BEFORE_HANDLER' => true,
			];
			if ($operatorId > 0)
			{
				// CURRENT_USER goes through Update() to EDITOR_ID and then to
				// timeline-event USER_ID (see PrepareUpdateEvent), so passing
				// the operator here pins the operator as the author of the
				// "activity completed" history record. Without it EDITOR_ID
				// falls back to the ambient $USER (visitor / cron-runner).
				$completeOptions['CURRENT_USER'] = $operatorId;
			}
			CCrmActivity::Complete($activityId, true, $completeOptions);
		}
	}

	/**
	 * Event handler when Transfer dialog to other roperator.
	 *
	 * @param Event $event
	 */
	public static function OnOperatorTransfer(Event $event): void
	{
		$activity = [];

		$parameters = $event->getParameters();
		$session = $parameters['SESSION'];
		if (!$session instanceof Session)
		{
			return;
		}
		
		$activityId = (int)($session->getData('CRM_ACTIVITY_ID') ?? 0);
		if ($activityId > 0)
		{
			$activity = CCrmActivity::GetByID($activityId, false);
		}

		$activity = is_array($activity) ? $activity : [];
		if (!empty($activity))
		{
			ActivityController::getInstance()
				->notifyTimelinesAboutActivityUpdate($activity, (int)$activity['RESPONSIBLE_ID'])
			;

			ProviderManager::syncBadgesOnActivityUpdate((int)$activity['ID'], $activity);
		}
	}
}
