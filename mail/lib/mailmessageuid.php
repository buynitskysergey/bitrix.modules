<?php

namespace Bitrix\Mail;

use Bitrix\Mail\Helper\Message\MessageInternalDateHandler;
use Bitrix\Mail\Helper\MessageEventManager;
use Bitrix\Mail\Internal\Service\Label\LabelCountersService;
use Bitrix\Mail\Internal\Service\Message\ClassifyPendingService;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internals\MailMessageMarkTable;
use Bitrix\Mail\Internals\MessageUploadQueueTable;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Entity;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

Localization\Loc::loadMessages(__FILE__);

/**
 * Class MailMessageUidTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_MailMessageUid_Query query()
 * @method static EO_MailMessageUid_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_MailMessageUid_Result getById($id)
 * @method static EO_MailMessageUid_Result getList(array $parameters = [])
 * @method static EO_MailMessageUid_Entity getEntity()
 * @method static \Bitrix\Mail\EO_MailMessageUid createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\EO_MailMessageUid_Collection createCollection()
 * @method static \Bitrix\Mail\EO_MailMessageUid wakeUpObject($row)
 * @method static \Bitrix\Mail\EO_MailMessageUid_Collection wakeUpCollection($rows)
 */
class MailMessageUidTable extends Entity\DataManager
{
	public const OLD = 'Y';
	public const RECENT = 'N';
	public const DOWNLOADED = 'D';
	public const MOVING = 'M';
	public const REMOTE = 'R';
	public const LOST = 'L';

	public const EXCLUDED_COUNTER_STATUSES = [
		self::LOST,
		self::MOVING,
		self::REMOTE,
		self::OLD,
	];

	public const HIDDEN_STATUSES = [
		self::LOST,
		self::MOVING,
		self::REMOTE,
	];

	private const REMAINING_MESSAGES_PORTION = 1000;

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_message_uid';
	}

	/**
	 * @param array $filter
	 * @param array $fields
	 * @param  array $eventData - optional, for compatibility reasons, should have the following structure:
	 * [ ['HEADER_MD5' => .., 'MESSAGE_ID' => .., 'MAILBOX_USER_ID' => ..], [..]]
	 * @return \Bitrix\Main\DB\Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 * @throws \Bitrix\Main\ObjectException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function updateList(array $filter, array $fields, array $eventData = [], bool $sendEvent = true)
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();

		$result = $connection->query(sprintf(
			"UPDATE %s SET %s WHERE %s",
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			$connection->getSqlHelper()->prepareUpdate($entity->getDbTableName(), $fields)[0],
			Entity\Query::buildFilterSql($entity, $filter)
		));
		$eventManager = EventManager::getInstance();
		$eventKey = $eventManager->addEventHandler(
			'mail',
			'onMailMessageModified',
			array(MessageEventManager::class, 'onMailMessageModified')
		);

		if ($sendEvent)
		{
			$event = new \Bitrix\Main\Event('mail', 'onMailMessageModified', array(
				'MAIL_FIELDS_DATA' => $eventData,
				'UPDATED_FIELDS_VALUES' => $fields,
				'UPDATED_BY_FILTER' => $filter,
			));
			$event->send();
			EventManager::getInstance()->removeEventHandler('mail', 'onMailMessageModified', $eventKey);
		}

		return $result;
	}

	/**
	 * @param array $filter
	 * @param array $messages
	 * @param int|false $limit
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function deleteList(array $filter, array $messages = [], $limit = false, bool $sendEvent = true): bool
	{
		$eventName = MessageEventManager::EVENT_DELETE_MESSAGES;

		$messages = static::selectMessagesToBeDeleted(
			MessageEventManager::getRequiredFieldNamesForEvent($eventName),
			$filter,
			$messages,
			$limit
		);

		if (empty($messages))
		{
			return false;
		}

		$entity = static::getEntity();
		$connection = $entity->getConnection();

		$portionLimit = 200;

		$messagesCount = count($messages);

		for ($i = 0; $i < $messagesCount; $i=$i+$portionLimit)
		{
			$portion = array_slice($messages, $i, $portionLimit);

			$query = sprintf(
				' FROM %s WHERE ID IN (\'' . join("','", array_column($portion, 'ID')) . '\')',
				$connection->getSqlHelper()->quote($entity->getDbTableName()),
			);

			self::insertIntoDeleteMessagesQueue($connection, $query);

			$connection->query(sprintf('DELETE %s', $query));
		}

		$remainingRows = static::selectRemainingUidRows($messages);

		static::removeMessageMarksAndPendingFlags($messages, $filter, $remainingRows);

		static::cleanupDeletedMessageLabels($messages, $filter, $remainingRows);

		/*
			A message has left the mailbox only when no uid row of it survives: the same letter
			may sit in another folder or in a retained source generation. Repeating the filter
			cannot answer that - it names the rows just deleted, not the message.
		*/
		$remains = array_column($remainingRows, 'MESSAGE_ID');

		if ($sendEvent)
		{
			//Checking that the values were actually deleted:
			$deletedMessages = array_filter(
				$messages,
				function ($item) use ($remains) {
					return !in_array($item['MESSAGE_ID'], $remains);
				}
			);

			$eventManager = EventManager::getInstance();
			$eventKey = $eventManager->addEventHandler(
				'mail',
				'onMailMessageDeleted',
				array(MessageEventManager::class, 'onMailMessageDeleted')
			);
			$event = new \Bitrix\Main\Event('mail', 'onMailMessageDeleted', array(
				'MAIL_FIELDS_DATA' => $deletedMessages,
				'DELETED_BY_FILTER' => $filter,
			));
			$event->send();
			EventManager::getInstance()->removeEventHandler('mail', 'onMailMessageDeleted', $eventKey);
		}

		return true;
	}

	/**
	 * A binding goes away with the last uid row of the message in that mailbox, as marks do.
	 */
	private static function cleanupDeletedMessageLabels(array $messages, array $filter, array $remainingRows): void
	{
		$deletedMessages = static::groupDeletedMessageIdsByMailbox(
			$messages,
			$remainingRows,
			$filter
		);

		$countersService = new LabelCountersService();

		foreach ($deletedMessages as $mailboxId => $messageIds)
		{
			$countersService->handleMessagesDeleted($mailboxId, $messageIds);
		}
	}

	/**
	 * Lives here and not in an onMailMessageDeleted handler: the deletion may be asked to keep silent,
	 * and the marks have to go anyway. The classify pending flag goes with the marks: process state of a
	 * letter that no longer exists. Best effort: a broken cleanup must not break the deletion itself.
	 */
	private static function removeMessageMarksAndPendingFlags(array $messages, array $filter, array $remainingRows): void
	{
		try
		{
			$deletedMessages = static::groupDeletedMessageIdsByMailbox(
				$messages,
				$remainingRows,
				$filter
			);

			$pendingService = new ClassifyPendingService();

			foreach ($deletedMessages as $mailboxId => $messageIds)
			{
				MailMessageMarkTable::deleteByMessages($mailboxId, $messageIds);
				$pendingService->clearMany($mailboxId, $messageIds);
			}
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * A message keeps its marks while any of its uid rows in the same mailbox survives - the same letter may
	 * sit in another folder. Mailbox id is not taken for granted in the deleted rows: a caller reaching this
	 * helper on its own may describe the letters without them, and then the filter is the source.
	 *
	 * @return array<int, int[]> Mailbox id => ids of messages that are gone.
	 */
	protected static function groupDeletedMessageIdsByMailbox(array $messages, array $remainingRows, array $filter): array
	{
		// A non-scalar filter value would cast to 1 and point the cleanup at a foreign mailbox
		$rawFilterMailboxId = $filter['=MAILBOX_ID'] ?? $filter['MAILBOX_ID'] ?? 0;
		$filterMailboxId = is_numeric($rawFilterMailboxId) ? (int)$rawFilterMailboxId : 0;
		$remaining = [];

		foreach ($remainingRows as $remainingRow)
		{
			if (is_array($remainingRow))
			{
				$remaining[(int)($remainingRow['MAILBOX_ID'] ?? 0)][(int)($remainingRow['MESSAGE_ID'] ?? 0)] = true;
			}
		}

		$grouped = [];

		foreach ($messages as $message)
		{
			if (!is_array($message))
			{
				continue;
			}

			$mailboxId = (int)($message['MAILBOX_ID'] ?? $filterMailboxId);
			$messageId = (int)($message['MESSAGE_ID'] ?? 0);

			if ($mailboxId <= 0 || $messageId <= 0 || isset($remaining[$mailboxId][$messageId]))
			{
				continue;
			}

			$grouped[$mailboxId][$messageId] = $messageId;
		}

		return array_map('array_values', $grouped);
	}

	/**
	 * @return array Uid rows that survived the deletion: MESSAGE_ID and MAILBOX_ID of each.
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function selectRemainingUidRows(array $messages): array
	{
		$messageIds = [];

		foreach ($messages as $message)
		{
			$messageId = is_array($message) ? (int)($message['MESSAGE_ID'] ?? 0) : 0;

			if ($messageId > 0)
			{
				$messageIds[$messageId] = $messageId;
			}
		}

		$remaining = [];

		foreach (array_chunk($messageIds, self::REMAINING_MESSAGES_PORTION) as $portion)
		{
			$rows = static::getList([
				'select' => [
					'MESSAGE_ID',
					'MAILBOX_ID',
				],
				'filter' => [
					'@MESSAGE_ID' => $portion,
				],
			])->fetchAll();

			$remaining = array_merge($remaining, $rows);
		}

		return $remaining;
	}

	public static function getLocalUID(int $mailboxId, string $dirPath, string $dirUIDv, string $order, ?GenerationScope $scope = null): int
	{
		$additionalFilter = [];

		if (\Bitrix\Mail\Helper\LicenseManager::getSyncOldLimit() > 0)
		{
			$additionalFilter = [
				'>INTERNALDATE' => \Bitrix\Main\Type\Date::createFromTimestamp(strtotime(sprintf('-%u days', \Bitrix\Mail\Helper\LicenseManager::getSyncOldLimit()))),
			];
		}

		$filter = array_merge([
			'=MAILBOX_ID' => $mailboxId,
			'=DIR_MD5' => md5($dirPath),
			'=DIR_UIDV' => $dirUIDv,
			'>MSG_UID' => 0,
			'=IS_OLD' => 'N',
			'!=MESSAGE_ID' => 0,
			'==DELETE_TIME' => 0,
		], $additionalFilter);

		// The same physical coordinates may exist in several generations
		if ($scope !== null)
		{
			$filter = $scope->apply($filter);
		}

		$row = self::getRow(
			[
				'select' => [
					'MSG_UID'
				],
				'filter' => $filter,
				'order' => [
					'MSG_UID' => $order,
				],
			]
		);

		if (!isset($row['MSG_UID']))
		{
			return 0;
		}

		return (int)$row['MSG_UID'];
	}

	public static function getLastLocalUID(int $mailboxId, string $dirPath, string $dirUIDv, ?GenerationScope $scope = null): int
	{
		return self::getLocalUID($mailboxId, $dirPath, $dirUIDv, 'DESC', $scope);
	}

	public static function getFirstLocalUID(int $mailboxId, string $dirPath, string $dirUIDv, ?GenerationScope $scope = null): int
	{
		return self::getLocalUID($mailboxId, $dirPath, $dirUIDv, 'ASC', $scope);
	}

	public static function getMessage(
		int $mailboxId,
		$select,
		int $id = null,
		int $uid = null,
	): array|null
	{
		if (!is_null($id))
		{
			$filter = [
				'=MESSAGE_ID' => $id,
				'=MAILBOX_ID' => $mailboxId,
			];
		}
		else if(!is_null($uid))
		{
			$filter = [
				'=MSG_UID' => $uid,
				'=MAILBOX_ID' => $mailboxId,
			];
		}
		else
		{
			return null;
		}

		return self::getRow(
			[
				'select' => $select,
				// The placement a message is read through is one of the generation serving the
				// mailbox now: a retained one names the coordinates of a source it has left
				'filter' => GenerationScope::forMailbox($mailboxId)->apply($filter),
			]
		);
	}

	/**
	 * Insert into delete queue table
	 *
	 * @param Connection $connection DB Connection
	 * @param string $query Query from and where
	 *
	 * @return void
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 */
	private static function insertIntoDeleteMessagesQueue(Connection $connection, string $query): void
	{
		$sqlHelper = $connection->getSqlHelper();
		$messageDeleteTableName = $sqlHelper->quote(Internals\MessageDeleteQueueTable::getTableName());
		// The queue row inherits the generation of the uid row it replaces
		$insertFields = ' (ID, MAILBOX_ID, MESSAGE_ID, GENERATION_ID) ';
		$fromSelect = sprintf('(SELECT ID, MAILBOX_ID, MESSAGE_ID, GENERATION_ID %s)', $query);
		$insertQuery = $sqlHelper->getInsertIgnore($messageDeleteTableName, $insertFields, $fromSelect);
		$connection->query($insertQuery);
	}

	public static function getPresetRemoveFilters()
	{
		return [
			'==DELETE_TIME' => 0,
		];
	}

	/**
	 * @param array $filter
	 * @return \Bitrix\Main\DB\Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function deleteListSoft(array $filter)
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();
		$filter = array_merge($filter , static::getPresetRemoveFilters());

		//mark selected messages for deletion if there are no messages in the download queue
		$query = sprintf(
			'UPDATE %s SET %s WHERE %s AND NOT EXISTS (SELECT 1 FROM %s WHERE %s)',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			$connection->getSqlHelper()->prepareUpdate($entity->getDbTableName(), [
				'DELETE_TIME' => time(),
			])[0],
			Entity\Query::buildFilterSql(
				$entity,
				$filter
			),
			$connection->getSqlHelper()->quote(Internals\MessageUploadQueueTable::getTableName()),
			Entity\Query::buildFilterSql(
				$entity,
				[
					'=ID' => new \Bitrix\Main\DB\SqlExpression('?#', 'ID'),
					'=MAILBOX_ID' => new \Bitrix\Main\DB\SqlExpression('?#', 'MAILBOX_ID'),
				]
			)
		);

		$result = $connection->query($query);
		$count = $connection->getAffectedRowsCount();
		$result->setCount($count > 0 ? $count : 0);

		return $result;
	}

	/**@
	 * @param $fields
	 * @param $filter
	 * @param array $eventData
	 * @param int|false $limit
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function selectMessagesToBeDeleted($fields, $filter, array $eventData, $limit = false): array
	{
		$result = array();

		$primary = array('ID', 'MAILBOX_ID');

		$eventData = array_values($eventData);

		if (empty($eventData))
		{
			$select = $fields;
		}
		elseif (array_diff($primary, array_intersect($primary, ...array_map('array_keys', $eventData))))
		{
			/*
				The caller knows the letters but not the rows that hold them: the primary key
				has to be read from the table. Checked before the event fields are complete -
				otherwise ready event data would be returned as the rows to delete, and a
				deletion by an empty list of ids would report success without deleting anything.
			*/
			$select = $fields;
		}
		else
		{
			$select = array_diff($fields, array_intersect($fields, ...array_map('array_keys', $eventData)));

			if (empty($select))
			{
				return $eventData;
			}

			foreach ($eventData as $item)
			{
				$key = sprintf('%u:%s', $item['MAILBOX_ID'], $item['ID']);
				$result[$key] = $item;
			}
		}

		$select = array_unique(array_merge($primary, $select));

		$mailsFilter = $filter;
		$mailsFilter['==IS_IN_QUEUE'] = false;
		$queueSubquery = MessageUploadQueueTable::query();
		$queueSubquery->addFilter('=ID', new \Bitrix\Main\DB\SqlExpression('%s'));
		$queueSubquery->addFilter('=MAILBOX_ID', new \Bitrix\Main\DB\SqlExpression('%s'));
		$emailsForDeleteQuery = MailMessageUidTable::query()
			->registerRuntimeField(new Entity\ExpressionField(
				'IS_IN_QUEUE',
				sprintf('EXISTS(%s)', $queueSubquery->getQuery()),
				['ID', 'MAILBOX_ID']
			))
			->setFilter($mailsFilter);

		if($limit !== false)
		{
			$emailsForDeleteQuery->setLimit($limit);
		}

		foreach ($select as $index => $selectingField)
		{
			if (strncmp('MAILBOX_', $selectingField, 8) === 0 && !MailMessageUidTable::getEntity()->hasField($selectingField))
			{
				$emailsForDeleteQuery->addSelect('MAILBOX.'.mb_substr($selectingField, 8), $selectingField);
				continue;
			}
			$emailsForDeleteQuery->addSelect($selectingField);
		}

		$res = $emailsForDeleteQuery->exec();
		while ($item = $res->fetch())
		{
			$key = sprintf('%u:%s', $item['MAILBOX_ID'], $item['ID']);
			$result[$key] = array_merge((array) $result[$key], $item);
		}

		return array_values($result);
	}

	/**
	 * Merge data. Insert-update.
	 *
	 * @param array $insert Insert fields.
	 * @param array $update Update fields.
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function mergeData(array $insert, array $update)
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();
		$helper = $connection->getSqlHelper();

		$sql = $helper->prepareMerge($entity->getDBTableName(), $entity->getPrimaryArray(), $insert, $update);

		$sql = current($sql);
		if($sql <> '')
		{
			$connection->queryExecute($sql);
			$entity->cleanCache();
		}
	}

	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type' => 'string',
				'primary'   => true,
			),
			'MAILBOX_ID' => array(
				'data_type' => 'integer',
				'primary'   => true,
			),
			'DIR_MD5' => array(
				'data_type' => 'string',
			),
			'DIR_UIDV' => array(
				'data_type' => 'integer',
				'size' => 8,
			),
			'MSG_UID' => array(
				'data_type' => 'integer',
				'size' => 8,
			),
			'INTERNALDATE' => array(
				'data_type' => 'datetime',
			),
			'HEADER_MD5' => array(
				'data_type' => 'string',
			),
			'IS_SEEN' => array(
				'data_type' => 'enum',
				'values'    => array('Y', 'N', 'S', 'U'),
			),
			'IS_OLD' => array(
				'data_type' => 'enum',
				'values'    => [
					self::OLD,
					self::RECENT,
					self::DOWNLOADED,
					self::MOVING,
					self::REMOTE,
					self::LOST,
				],
			),
			'SESSION_ID' => array(
				'data_type' => 'string',
				'required'  => true,
			),
			'TIMESTAMP_X' => array(
				'data_type' => 'datetime',
			),
			'DATE_INSERT' => array(
				'data_type' => 'datetime',
				'required'  => true,
			),
			'MESSAGE_ID' => array(
				'data_type' => 'integer',
				'required'  => true,
			),
			'MAILBOX' => array(
				'data_type' => 'Bitrix\Mail\Mailbox',
				'reference' => array('=this.MAILBOX_ID' => 'ref.ID'),
			),
			'MESSAGE' => array(
				'data_type' => 'Bitrix\Mail\MailMessage',
				'reference' => array('=this.MESSAGE_ID' => 'ref.ID'),
			),
			'DELETE_TIME' => array(
				'data_type' => 'integer',
				'default' => 0,
			),
			'GENERATION_ID' => array(
				'data_type' => 'integer',
				'default_value' => 0,
			),
			new Reference(
				'MESSAGE_TABLE',
				MailMessageTable::class,
				Join::on('this.MESSAGE_ID', 'ref.ID')
					->whereColumn('this.MAILBOX_ID', 'ref.MAILBOX_ID')
			),
		);
	}
	/**
	 * @param Entity\Event $event
	 * @return Entity\EventResult
	 */
	public static function onAfterUpdate(Entity\Event $event)
	{
		$result = new Entity\EventResult;
		$parameters = $event->getParameters();

		if (!$parameters['primary'] || !is_set($parameters['fields']['IS_OLD']))
		{
			return $result;
		}

		$message = self::getByPrimary($parameters['primary'], [
			'select' => [
				'MAILBOX_ID',
				'DIR_MD5',
				'INTERNALDATE',
				'IS_OLD',
				'DELETE_TIME',
				'MESSAGE_ID',
			],
		])->fetch();

		if (!$message)
		{
			return $result;
		}

		if (self::shouldInvalidateStartDateCacheOnUpdate($message))
		{
			$updateResult = MessageInternalDateHandler::clearStartInternalDate(
				(int)$message['MAILBOX_ID'],
				$message['DIR_MD5'],
			);
			if (!$updateResult->isSuccess())
			{
				$result->setErrors($updateResult->getErrors());
			}
		}

		return $result;
	}

	/**
	 * The write-path invalidation once the read path took over recalculation. The cache is read without a
	 * side effect - this handler never recalculates. A counted letter strictly below the stored minimum, a
	 * folder cached empty, or a letter no later than the minimum leaving the counted set makes the cache wrong.
	 * The comparison for a counted letter is strict because equality does not change the minimum. Reverse of the
	 * move path
	 * ({@see MessageEventManager::checkForNeedClearCache}), where the letter leaves the folder and equality
	 * does move the minimum.
	 *
	 * @param array<string, mixed> $message
	 */
	private static function shouldInvalidateStartDateCacheOnUpdate(array $message): bool
	{
		if (
			!isset($message['DELETE_TIME']) || (int)$message['DELETE_TIME'] !== 0
			|| !isset($message['MESSAGE_ID']) || (int)$message['MESSAGE_ID'] <= 0
			|| !isset($message['INTERNALDATE']) || $message['INTERNALDATE'] === null
			|| !isset($message['IS_OLD'])
		)
		{
			return false;
		}

		$cache = MessageInternalDateHandler::getCachedStartInternalDateForDir(
			(int)$message['MAILBOX_ID'],
			(string)$message['DIR_MD5'],
		);

		if ($cache === null)
		{
			return false;
		}

		if (MessageInternalDateHandler::isCountableMessageRow($message))
		{
			return $cache->value === null || $message['INTERNALDATE'] < $cache->value;
		}

		return in_array($message['IS_OLD'], self::EXCLUDED_COUNTER_STATUSES, true)
			&& $cache->value !== null
			&& $message['INTERNALDATE'] <= $cache->value
		;
	}

}
