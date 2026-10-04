<?php

namespace Bitrix\Crm\Activity\Mail;

use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Security\PermissionToken;
use Bitrix\Disk\File;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mail\Helper\Dto\MailMessageChain;
use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\Type\Date;
use Bitrix\Mobile\UI;
use Bitrix\UI\EntitySelector\ItemCollection;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\Mail\Helper\AbstractMailMessageChainProvider;
use Bitrix\Mail\Helper\Message\Parsers;

class MailMessageChainProvider extends AbstractMailMessageChainProvider
{
	protected const PERMISSION_READ = 1;
	protected const SUPPORTED_ACTIVITY_TYPE = 'CRM_EMAIL';
	private const READ_ACCESS_NONE = 0;
	private const READ_ACCESS_PROVIDER = 1;
	private const READ_ACCESS_TOKEN = 2;

	public ErrorCollection $errorCollection;
	private ?string $permissionToken;

	public function __construct(?string $permissionToken = null)
	{
		$this->errorCollection = new ErrorCollection();
		$this->permissionToken = $permissionToken ?: null;
	}

	private function canReadActivityByToken(int $activityId): bool
	{
		if ($this->permissionToken === null || $activityId <= 0)
		{
			return false;
		}

		return PermissionToken::canViewActivity(
			$this->permissionToken,
			$activityId,
			\CCrmSecurityHelper::getCurrentUserId(),
		);
	}

	private function hasReadPermission(array $activity): bool
	{
		$provider = \CCrmActivity::getActivityProvider($activity);

		return $provider && $provider::checkReadPermission($activity, \CCrmSecurityHelper::getCurrentUserId());
	}

	private function resolveReadAccess(array $activity): int
	{
		if ($this->hasReadPermission($activity))
		{
			return self::READ_ACCESS_PROVIDER;
		}

		if ($this->canReadActivityByToken((int)($activity['ID'] ?? 0)))
		{
			return self::READ_ACCESS_TOKEN;
		}

		return self::READ_ACCESS_NONE;
	}

	private function canUpdateActivity(array $activity): bool
	{
		$provider = \CCrmActivity::getActivityProvider($activity);

		return $provider && $provider::checkUpdatePermission($activity, \CCrmSecurityHelper::getCurrentUserId());
	}

	private function isReadOnlyActivity(array $activity): bool
	{
		if ($this->resolveReadAccess($activity) !== self::READ_ACCESS_PROVIDER)
		{
			return true;
		}

		return !$this->canUpdateActivity($activity);
	}

	protected function replaceAttachmentPlaceholderWithUrl(string $body, int $imageId, string $url): string
	{
		$newBody = parent::replaceAttachmentPlaceholderWithUrl($body, $imageId, $url);
		return Parsers\ReplacingUrlImagesToAbsolute::parse($newBody, $imageId, $url);
	}

	private function checkActivityIsType(array $activity, string $type = self::SUPPORTED_ACTIVITY_TYPE): bool
	{
		$provider = \CCrmActivity::getActivityProvider($activity);

		if (!$provider)
		{
			return false;
		}

		if ($provider::getId() === $type)
		{
			return true;
		}

		return false;
	}

	private function getActivities(array $filters, string $activityType, array $select = [], array $order = [], int $limit = 50): array
	{
		$processedActivities = [];

		$requiredFieldsForChecks = [
			'ID',
			'TYPE_ID',
			'PROVIDER_ID',
			'RESPONSIBLE_ID',
			'OWNER_TYPE_ID',
			'OWNER_ID',
		];

		$activities = ActivityTable::getList([
			'select' => array_unique(array_merge($select, $requiredFieldsForChecks)),
			'filter' => $filters,
			'order' => $order,
			'limit' => $limit,
		])->fetchAll();

		foreach ($activities as &$activity)
		{
			if ($this->checkActivityIsType($activity, $activityType))
			{
				\CCrmActivity::PrepareStorageElementIDs($activity);
				$processedActivities[] = $activity;
			}
			else
			{
				$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_CAN_NOT_DETERMINE_THE_MESSAGE_FORMAT'));
			}
		}

		return $processedActivities;
	}

	protected function checkActivityPermission(int $permission = self::PERMISSION_READ, array $activities = []): bool
	{
		if (count($activities) === 0)
		{
			return false;
		}

		$activity = $activities[0];

		if (!isset($activity['OWNER_TYPE_ID']) || !isset($activity['OWNER_ID']))
		{
			return false;
		}

		if (
			($permission === self::PERMISSION_READ)
			&& $this->resolveReadAccess($activity) !== self::READ_ACCESS_NONE
		)
		{
			return true;
		}

		return false;
	}

	protected function getHeaderMessage(array $activity): array
	{
		$headerResult = Message::getHeader(
			$activity,
			true,
			$this->permissionToken,
			true
		);
		$this->errorCollection->add($headerResult->getErrors());

		return $headerResult->getData();
	}

	private function filterProviderReadableActivities(array $activities): array
	{
		return array_values(
			array_filter(
				$activities,
				fn ($activity) => $this->hasReadPermission($activity)
			)
		);
	}

	/**
	 * @param int $id
	 * @param bool $takeBody
	 * @param bool $takeFiles
	 * @return MailMessage
	 * @throws LoaderException
	 * @throws NotImplementedException
	 */
	public function getMessage(int $id, bool $takeBody = false, bool $takeFiles = false): MailMessage
	{
		$message = new MailMessage();

		if ($takeBody)
		{
			$message->body = $this->cleanCharset($this->getMessageBody($id));
		}

		if ($takeFiles)
		{
			$message->attachments = $this->getAttachmentsWithMessageId($id)['FILES'];
			$message->body = $this->replaceAttachmentPlaceholders($message->body, $message->attachments);
		}

		return $message;
	}

	private function getMessageBody($id): ?string
	{
		$body = null;

		$activities = $this->getActivities(
			[
				'ID' => $id,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			[
				'DESCRIPTION',
				'PROVIDER_TYPE_ID',
				'ASSOCIATED_ENTITY_ID',
			]
		);

		if (!$this->checkActivityPermission(self::PERMISSION_READ, $activities))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_PERMISSION_DENIED'));

			return null;
		}

		$activity = $activities[0];

		if (is_array($activity))
		{
			Email::uncompressActivityDescription($activity);

			if (isset($activity['DESCRIPTION']))
			{
				$body = (string)$activity['DESCRIPTION'];
			}
		}

		return $body;
	}


	/**
	 * @param int $id
	 * @param bool $forMobile
	 * @param bool $checkPermissions
	 * @return array {FILES: array, ID: int} - array of files info and activity ID
	 * (TODO: return DTO)
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\NotImplementedException
	 */
	public function getAttachmentsWithMessageId(int $id, bool $forMobile = true, bool $checkPermissions = true): array
	{
		$filesInfo = [
			'FILES' => [],
			'ID' => 0,
		];

		if (!\Bitrix\Main\Loader::includeModule('disk'))
		{
			return $filesInfo;
		}

		$activities = $this->getActivities(
			[
				'ID' => $id,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			[
				'STORAGE_ELEMENT_IDS',
			]
		);

		if ($checkPermissions === true && !$this->checkActivityPermission(self::PERMISSION_READ, $activities))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_PERMISSION_DENIED'));

			return $filesInfo;
		}

		if (count($activities) === 0)
		{
			return $filesInfo;
		}

		$activity = $activities[0];

		$filesInfo['ID'] = (int)$activity['ID'];

		$filesIDs = [];

		if(is_array($activity['STORAGE_ELEMENT_IDS']))
		{
			$filesIDs = array_unique($activity['STORAGE_ELEMENT_IDS'], SORT_NUMERIC);
		}

		$diskFiles = File::loadBatchById($filesIDs);

		$realToDiskMap = [];
		$realFileIds = [];

		/** @var \Bitrix\Disk\File $diskFile */
		foreach ($diskFiles as $diskFile)
		{
			$realId = (int)$diskFile->getFileId();
			$diskId = (int)$diskFile->getId();

			$realFileIds[] = $realId;
			$realToDiskMap[$realId] = $diskId;
		}

		if (empty($realFileIds))
		{
			return $filesInfo;
		}

		if ($forMobile)
		{
			if (!\Bitrix\Main\Loader::includeModule('mobile'))
			{
				return $filesInfo;
			}

			foreach ($realToDiskMap as $realId => $diskId)
			{
				$diskFile = UI\File::loadWithPreview($realId);

				if ($diskFile)
				{
					$diskFileInfo = $diskFile->getInfo();
					$diskFileInfo[self::KEY_ID_IN_MESSAGE_BODY] = $diskId;
					$filesInfo['FILES'][] = $diskFileInfo;
				}
			}

			return $filesInfo;
		}

		$filesRows = \CFile::getList(arFilter: [
			'@ID' => $realFileIds,
		]);

		while ($fileInfo = $filesRows->Fetch())
		{
			if (!isset($file['SRC']))
			{
				$fileInfo['SRC'] = \CFile::GetFileSRC($fileInfo);
			}

			$fileId = (int)$fileInfo['ID'];

			if (!array_key_exists($fileId, $realToDiskMap))
			{
				continue;
			}

			$filesInfo['FILES'][] = [
				self::KEY_ID_IN_MESSAGE_BODY => $realToDiskMap[$fileId],
				'url' => $fileInfo['SRC'],
				'name' => (string)($fileInfo['ORIGINAL_NAME'] ?? ''),
			];
		}

		return $filesInfo;
	}

	/**
	 * @param array $contacts
	 * @param bool $isUser
	 * @return ItemCollection
	 */
	private function convertToMailContactList(array $contacts, bool $isUser = false): ItemCollection
	{
		$list = new ItemCollection();

		if (empty($contacts))
		{
			return $list;
		}

		foreach ($contacts as $contact)
		{
			$list->add(new Item([
				'id' => (string)$contact['id'],
				'customData'=>  [
					'isUser' => (bool)($contact['isUser'] ?? $isUser),
					'typeName' => (string)($contact['typeName'] ?? ''),
					'typeNameId' => (string)($contact['typeNameId'] ?? ''),
					'name' => (string)$contact['name'],
					'email' => (string)$contact['email'],
				],
			]));
		}

		return $list;
	}

	private function getActivityTimestamp(array $activity): int
	{
		$startTime = $activity['START_TIME'] ?? null;

		return $startTime instanceof Date
			? $startTime->getTimestamp()
			: 0
		;
	}

	private function buildMessageFromActivity(array $item, bool $loadContent): MailMessage
	{
		$message = new MailMessage();
		$message->id = (int)$item['ID'];
		$message->subject = (string)($item['SUBJECT'] ?? '');
		$message->date = $this->getActivityTimestamp($item);
		$message->ownerTypeId = (int)$item['OWNER_TYPE_ID'];
		$message->ownerId = (int)$item['OWNER_ID'];
		$message->ownerType = \CCrmOwnerType::ResolveName($item['OWNER_TYPE_ID']);
		$message->direction = (int)$item['DIRECTION'];

		$header = $this->getHeaderMessage($item);

		$message->availableSenders = $this->convertToMailContactList($header['accessMailboxesForSending'] ?? [], true);
		$message->employees = $this->convertToMailContactList($header['employeeEmails'] ?? [], true);
		$message->from = $this->convertToMailContactList($header['from'] ?? []);
		$message->replyTo = $this->convertToMailContactList($header['replyTo'] ?? []);
		$message->cc = $this->convertToMailContactList($header['cc'] ?? []);
		$message->bcc = $this->convertToMailContactList($header['bcc'] ?? []);
		$message->to = $this->convertToMailContactList($header['to'] ?? []);

		if ($loadContent)
		{
			$activityId = (int)$item['ID'];
			$message->body = $this->cleanCharset($this->getMessageBody($activityId));
			$message->attachments = $this->getAttachmentsWithMessageId($activityId)['FILES'];
			$message->body = $this->replaceAttachmentPlaceholders($message->body, $message->attachments);
		}

		return $message;
	}

	/**
	 * @param int $messageId
	 * @return MailMessageChain
	 * @throws LoaderException
	 * @throws NotImplementedException
	 */
	public function getChain(int $messageId): MailMessageChain
	{
		$mailMessageChain = new MailMessageChain();

		$select = [
			'SETTINGS',
			'PARENT_ID',
			'THREAD_ID',
			'SUBJECT',
			'START_TIME',
			'DIRECTION',
		];

		$activities = $this->getActivities(
			[
				'ID' => $messageId,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			$select,
			limit: 1
		);

		if (count($activities) === 0)
		{
			return $mailMessageChain;
		}

		$activity = $activities[0];

		if (!isset($activity['OWNER_TYPE_ID']) || !isset($activity['OWNER_ID']))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_CAN_NOT_DETERMINE_THE_MESSAGE_FORMAT'));

			return $mailMessageChain;
		}

		$readAccess = $this->resolveReadAccess($activity);
		if ($readAccess === self::READ_ACCESS_NONE)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_LIB_MESSAGE_CHAIN_PROVIDER_PERMISSION_DENIED'));

			return $mailMessageChain;
		}

		if ($readAccess === self::READ_ACCESS_TOKEN)
		{
			$mailMessageChain->list[] = $this->buildMessageFromActivity($activity, true);
			$mailMessageChain->properties = [
				'lastIncomingId' => null,
				'readOnly' => true,
			];

			return $mailMessageChain;
		}

		$threadId = $activity['THREAD_ID'];

		$order = [
			'START_TIME' => 'DESC',
		];

		/*
			We need to make two selections and sort the messages by date
			in order to limit the number of messages in long chains(in the future)
			while preserving the open email in the middle of the chain.
		 */
		$messageBeforeCurrent = $this->getActivities(
			[
				'=THREAD_ID' => $threadId,
				'<=PARENT_ID' => $messageId,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			$select,
			$order
		);

		$messageAfterCurrent = $this->getActivities(
			[
				'=THREAD_ID' => $threadId,
				'>PARENT_ID' => $messageId,
			],
			self::SUPPORTED_ACTIVITY_TYPE,
			$select,
			$order
		);

		$chainActivities = $this->filterProviderReadableActivities(array_merge($messageBeforeCurrent, $messageAfterCurrent));

		/*
			We need to sort the messages by time again.
			For example, message number 5 may be a response to the first message, not the fourth.
		*/
		usort($chainActivities, function($a, $b) {
			return $this->getActivityTimestamp($b) <=> $this->getActivityTimestamp($a);
		});

		$lastIncomingId = null;
		$lastIncomingKey = null;

		foreach ($chainActivities as $item)
		{
			$isCurrent = (int)$item['ID'] === $messageId;
			$message = $this->buildMessageFromActivity($item, $isCurrent);

			$mailMessageChain->list[] = $message;
			$messageKey = array_key_last($mailMessageChain->list);

			if (is_null($lastIncomingId) && $message->direction === MailMessage::DIRECTION_INCOMING)
			{
				$lastIncomingId = (int)$item['ID'];
				$lastIncomingKey = $messageKey;
			}
		}

		/*
			Upload the content of the last read message to be able to transfer it to the sending component for citation
		 */
		if (
			!is_null($lastIncomingKey)
			&& isset($mailMessageChain->list[$lastIncomingKey])
			&& $lastIncomingId !== $messageId
		)
		{
			$mailMessageChain->list[$lastIncomingKey]->body = $this->cleanCharset($this->getMessageBody($lastIncomingId));
			$mailMessageChain->list[$lastIncomingKey]->attachments = $this->getAttachmentsWithMessageId($lastIncomingId)['FILES'];
			$mailMessageChain->list[$lastIncomingKey]->body = $this->replaceAttachmentPlaceholders($mailMessageChain->list[$lastIncomingKey]->body, $mailMessageChain->list[$lastIncomingKey]->attachments);
		}

		$mailMessageChain->properties = [
			'lastIncomingId' => $lastIncomingId,
			'readOnly' => $this->isReadOnlyActivity($activity),
		];

		return $mailMessageChain;
	}
}
