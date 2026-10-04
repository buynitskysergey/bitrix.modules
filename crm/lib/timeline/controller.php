<?php

namespace Bitrix\Crm\Timeline;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Repository\IgnoredItemsRules;
use Bitrix\Main;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

class Controller
{
	/**
	 * Get an instance of the controller
	 *
	 * @return static
	 */
	public static function getInstance()
	{
		if (!ServiceLocator::getInstance()->has(static::getServiceLocatorIdentifier()))
		{
			$instance = new static();
			ServiceLocator::getInstance()->addInstance(static::getServiceLocatorIdentifier(), $instance);
		}

		return ServiceLocator::getInstance()->get(static::getServiceLocatorIdentifier());
	}

	protected static function getServiceLocatorIdentifier(): string
	{
		return Container::getIdentifierByClassName(static::class);
	}

	/**
	 * Prepare data about an timeline entry. The data is used in interface to display timeline event
	 *
	 * @param array $data
	 * @param array|null $options = [
	 *     'ENABLE_USER_INFO' => false, // prepare detailed author info (link, image, name). Disabled by default
	 * ];
	 *
	 * @return array
	 */
	public function prepareHistoryDataModel(array $data, array $options = null)
	{
		if(!is_array($options))
		{
			$options = array();
		}

		if(isset($options['ENABLE_USER_INFO']) && $options['ENABLE_USER_INFO'] === true)
		{
			self::prepareAuthorInfo($data);
		}

		if(isset($data['CREATED']) && $data['CREATED'] instanceof DateTime)
		{
			$data['CREATED_SERVER'] = $data['CREATED']->format('Y-m-d H:i:s');
		}

		unset($data['SETTINGS']);
		return $data;
	}
	public static function prepareAuthorInfo(array &$item)
	{
		$items = array($item);
		self::prepareAuthorInfoBulk($items);
		$item = $items[0];
	}
	public static function prepareAuthorInfoBulk(array &$items)
	{
		$userMap = array();
		foreach($items as $ID => &$item)
		{
			if(!is_array($item))
			{
				continue;
			}

			$authorID = isset($item['AUTHOR_ID']) ? (int)$item['AUTHOR_ID'] : 0;
			if($authorID <= 0)
			{
				continue;
			}

			if(!isset($userMap[$authorID]))
			{
				$userMap[$authorID] = array();
			}
			$userMap[$authorID][] = $ID;
		}
		unset($item);

		if(!empty($userMap))
		{
			$userIDs = array_keys($userMap);
			$users = Container::getInstance()->getUserBroker()->getBunchByIds($userIDs);
			foreach ($users as $user)
			{
				$userID = (int)$user['ID'];

				$userName = \CUser::FormatName(\CSite::getNameFormat(), $user, true, false);

				foreach($userMap[$userID] as $ID)
				{
					$items[$ID]['AUTHOR'] = array(
						'FORMATTED_NAME' => $userName,
						'SHOW_URL' => (string)$user['SHOW_URL'],
					);

					if (isset($user['PHOTO_URL']))
					{
						$items[$ID]['AUTHOR']['IMAGE_URL'] = (string)$user['PHOTO_URL'];
					}
				}
			}
		}
	}

	/**
	 * @deprecated
	 * @see Controller::sendPullEventOnAdd(), Controller::sendPullEventOnUpdate(), Controller::sendPullEventOnDelete()
	 */
	protected static function pushHistoryEntry($entryID, $tagName, $command)
	{
		if(!Main\Loader::includeModule('pull'))
		{
			return;
		}

		$params = array('TAG' => $tagName);
		$entryFields = TimelineEntry::getByID($entryID);
		if(is_array($entryFields))
		{
			TimelineManager::prepareItemDisplayData($entryFields);
			$params['HISTORY_ITEM'] = $entryFields;
		}

		\CPullWatch::AddToStack(
			$tagName,
			array(
				'module_id' => 'crm',
				'command' => $command,
				'params' => $params,
			)
		);
	}

	protected static function getDefaultAuthorId()
	{
		return \Bitrix\Crm\Service\SystemUser::getDefaultAuthorId();
	}

	protected static function getCurrentOrDefaultAuthorId(): int
	{
		$context = Container::getInstance()->getContext();

		// 1. The explicitly set context user (delivered by the caller — an explicit
		//    parameter or a local background context) wins.
		// 2. Otherwise the ambient current user is trusted only for interactive
		//    request scopes: in background, automation and AI scopes the current
		//    user is the runner or the triggering session, not necessarily the
		//    actor, so we skip it and use the system author.
		$resolved = (int)($context->getExplicitUserId() ?? 0);
		if ($resolved > 0)
		{
			return $resolved;
		}

		if (!$context->isNonInteractiveScope())
		{
			$currentUserId = $context->getUserId();
			if ($currentUserId > 0)
			{
				return $currentUserId;
			}
		}

		return (int)static::getDefaultAuthorId();
	}

	/**
	 * Create timeline Item object by timeline db item id
	 *
	 * @param Context $context
	 * @param int $timelineEntryId
	 * @return \Bitrix\Crm\Service\Timeline\Item|null
	 */
	protected function createItemByTimelineEntryId(Context $context, int $timelineEntryId):
		?\Bitrix\Crm\Service\Timeline\Item
	{
		if ($timelineEntryId <= 0)
		{
			return null;
		}

		$timelineEntry = $this->getTimelineEntryFacade()->getById($timelineEntryId);
		if (!$timelineEntry)
		{
			return null;
		}
		if ((new IgnoredItemsRules($context))->isTimelineItemIgnored($timelineEntry))
		{
			return null;
		}

		$timelineEntry['IS_FIXED'] = \Bitrix\Crm\Timeline\TimelineEntry::isFixed(
			$timelineEntryId, $context->getEntityTypeId(), $context->getEntityId()
		)  ? 'Y' : 'N';

		$timelineEntryArray = [$timelineEntry];

		TimelineManager::prepareDisplayData(
			$timelineEntryArray,
			$context->getUserId(),
			$context->getUserPermissions()->getCrmPermissions(),
			$context->getType() !== Context::PULL
		);

		$timelineEntry = $timelineEntryArray[0] ?? null; // $timelineEntryArray can be modified in case of insufficient permissions
		if (!$timelineEntry)
		{
			return null;
		}

		return Container::getInstance()->getTimelineHistoryItemFactory()::createItem(
			$context,
			$timelineEntry
		);
	}

	/**
	 * Send pull event about timeline item creation
	 *
	 * @param ItemIdentifier $itemIdentifier
	 * @param int $timelineEntryId
	 * @param int|null $userId
	 * @return void
	 */
	public function sendPullEventOnAdd(ItemIdentifier $itemIdentifier, int $timelineEntryId, int $userId = null): void
	{
		if (!Container::getInstance()->getTimelinePusher()->isDetailsPageChannelActive($itemIdentifier))
		{
			return;
		}

		$item = $this->createItemByTimelineEntryId(
			new Context($itemIdentifier, Context::PULL, $userId),
			$timelineEntryId
		);
		if ($item)
		{
			(new \Bitrix\Crm\Service\Timeline\Item\Pusher($item))->sendAddEvent();
		}
	}

	/**
	 * Send pull event about timeline item modification
	 *
	 * @param ItemIdentifier $itemIdentifier
	 * @param int $timelineEntryId
	 * @param int|null $userId
	 * @return void
	 */
	public function sendPullEventOnUpdate(ItemIdentifier $itemIdentifier, int $timelineEntryId, int $userId = null): void
	{
		if (!Container::getInstance()->getTimelinePusher()->isDetailsPageChannelActive($itemIdentifier))
		{
			return;
		}

		$item = $this->createItemByTimelineEntryId(
			new Context($itemIdentifier, Context::PULL, $userId),
			$timelineEntryId
		);
		if ($item)
		{
			$item->getModel()->setIsFixed(false); // update pull event should be sent about base history item

			(new \Bitrix\Crm\Service\Timeline\Item\Pusher($item))->sendUpdateEvent();
		}
	}

	/**
	 * Send pull event about timeline item deletion
	 *
	 * @param ItemIdentifier $itemIdentifier
	 * @param int $timelineEntryId
	 * @param int|null $userId
	 * @return void
	 */
	public function sendPullEventOnDelete(ItemIdentifier $itemIdentifier, int $timelineEntryId, int $userId = null): void
	{
		if (!Container::getInstance()->getTimelinePusher()->isDetailsPageChannelActive($itemIdentifier))
		{
			return;
		}

		$item = \Bitrix\Crm\Service\Timeline\Item\Factory\HistoryItem::createEmptyItem(
			new Context($itemIdentifier, Context::PULL, $userId),
			$timelineEntryId
		);

		(new \Bitrix\Crm\Service\Timeline\Item\Pusher($item))->sendDeleteEvent();
	}

	public function sendPullEventOnPin(
		ItemIdentifier $itemIdentifier,
		int $timelineEntryId,
		bool $isPinned,
		int $userId = null
	)
	{
		if (!Container::getInstance()->getTimelinePusher()->isDetailsPageChannelActive($itemIdentifier))
		{
			return;
		}

		$itemTo = $this->createItemByTimelineEntryId(
			new Context($itemIdentifier, Context::PULL, $userId),
			$timelineEntryId
		);
		if ($itemTo)
		{
			(new \Bitrix\Crm\Service\Timeline\Item\Pusher($itemTo))->sendChangePinnedEvent(
				$isPinned
					? \Bitrix\Crm\Service\Timeline\Item\Pusher::STREAM_HISTORY
					: \Bitrix\Crm\Service\Timeline\Item\Pusher::STREAM_FIXED_HISTORY
				,
				$timelineEntryId
			);
		}
	}

	protected function getTimelineEntryFacade(): TimelineEntry\Facade
	{
		return Container::getInstance()->getTimelineEntryFacade();
	}
}