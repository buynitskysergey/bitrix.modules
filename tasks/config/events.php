<?php

use Bitrix\Tasks\V2\Internal\Integration\Im;
use Bitrix\Tasks\V2\Internal\Integration\Rest;
use Bitrix\Tasks\V2\Internal\Integration\Bizproc;
use Bitrix\Tasks\V2\Internal\Integration\Disk;

return [
	'value' => [
		\Bitrix\Im\V2\Chat\ExternalChat\Event\AfterDeleteMessagesEvent::class => [
			Im\EventHandler\OnAfterMessagesDeletedEvent\UpdateCounters::class,
		],
		\Bitrix\Im\V2\Chat\ExternalChat\Event\AfterReadAllMessagesEvent::class => [
			Im\EventHandler\OnAfterReadAllMessagesEvent\UpdateCounters::class,
			Im\EventHandler\OnAfterReadAllMessagesEvent\SendPushNotification::class,
		],
		\Bitrix\Im\V2\Chat\ExternalChat\Event\AfterReadMessagesEvent::class => [
			Im\EventHandler\OnAfterReadMessageEvent\UpdateCounters::class,
			Im\EventHandler\OnAfterReadMessageEvent\SendPushNotification::class,
		],
		\Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent::class => [
			Im\EventHandler\OnAfterSendMessageEvent\UpdateLastActivityDate::class,
			Im\EventHandler\OnAfterSendMessageEvent\AddUserToAuditors::class,
			Im\EventHandler\OnAfterSendMessageEvent\AutoUnmuteAuditor::class,
			Im\EventHandler\OnAfterSendMessageEvent\UpdateCounters::class,
			Im\EventHandler\OnAfterSendMessageEvent\SendPushNotification::class,
			Rest\EventHandler\OnAfterSendMessageEvent\ExecuteRestEvent::class,
		],
		\Bitrix\Im\V2\Chat\ExternalChat\Event\AfterMuteEvent::class => [
			Im\EventHandler\OnAfterMuteEvent\TaskSync::class,
		],
		\Bitrix\Im\V2\Message\Event\AfterReadAllChatsEvent::class => [
			Im\EventHandler\OnAfterReadAllChats\UpdateCounters::class,
			Im\EventHandler\OnAfterReadAllChats\SendPushNotification::class,
		],
		\Bitrix\Im\V2\Message\Event\AfterReadChatsByTypeBatchEvent::class => [
			Im\EventHandler\OnAfterReadChatsByTypeBatchTasksTask\UpdateCounters::class,
			Im\EventHandler\OnAfterReadChatsByTypeBatchTasksTask\SendPushNotification::class,
		],
		\Bitrix\Im\V2\Chat\ExternalChat\Event\AfterUsersAddEvent::class => [
			Im\EventHandler\OnAfterUsersAdded\AddUsersToAuditors::class,
		],
		\Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\OnGetDocumentTypeEvent::class => [
			Bizproc\EventHandler\OnGetDocumentTypeEvent\GetDocumentTypes::class,
		],
		\Bitrix\Disk\Public\Event\OnAfterDeleteAttachedObjectEvent::class => [
			Disk\EventHandler\OnAfterDeleteAttachedObject\DetachFile::class,
		],
	],
];
