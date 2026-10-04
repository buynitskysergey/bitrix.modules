<?php

use Bitrix\Mail\Internal\Async\Model\MessengerTable;
use Bitrix\Mail\Internal\Async\Receiver\ClassifyMailMessageReceiver;
use Bitrix\Mail\Internal\Async\Receiver\MailboxAccessNotificationReceiver;
use Bitrix\Mail\Internal\Async\Receiver\MailboxMigrationNotificationReceiver;
use Bitrix\Mail\Internal\Async\Receiver\OrphanedMailboxAutoDisconnectNotificationReceiver;
use Bitrix\Mail\Internal\Async\Receiver\RepairConnectionRequestChatsReceiver;
use Bitrix\Mail\Integration\UI\EntitySelector\AddressBookProvider;
use Bitrix\Mail\Integration\UI\EntitySelector\MailboxProvider;
use Bitrix\Mail\Integration\UI\EntitySelector\MailCrmRecipientProvider;
use Bitrix\Mail\Integration\UI\EntitySelector\MassConnectUserProvider;
use Bitrix\Mail\Integration\UI\EntitySelector\MailUserRecipientAppearanceFilter;
use Bitrix\Mail\Integration\UI\EntitySelector\MailCrmRecipientAppearanceFilter;
use Bitrix\Mail\Integration\UI\EntitySelector\DiscussInChatAppearanceFilter;


return array(
	'controllers' => array(
		'value' => array(
			'namespaces' => array(
				'\\Bitrix\\Mail\\Controller' => 'api',
			),
			'defaultNamespace' => '\\Bitrix\\Mail\\Controller',
		),
		'readonly' => true,
	),
	'ui.selector' => [
		'value' => [
			'mail.selector'
		],
		'readonly' => true,
	],
	'ui.entity-selector' => [
		'value' => [
			'filters' => [
				[
					'id' => 'mail.mailUserRecipientAppearanceFilter',
					'entityId' => 'user',
					'className' => MailUserRecipientAppearanceFilter::class,
				],
				[
					'id' => 'mail.mailCrmRecipientAppearanceFilter',
					'entityId' => 'contact',
					'className' => MailCrmRecipientAppearanceFilter::class,
				],
				[
					'id' => 'mail.mailCrmRecipientAppearanceFilter',
					'entityId' => 'company',
					'className' => MailCrmRecipientAppearanceFilter::class,
				],
				[
					'id' => 'mail.mailCrmRecipientAppearanceFilter',
					'entityId' => 'lead',
					'className' => MailCrmRecipientAppearanceFilter::class,
				],
				[
					'id' => 'mail.discussInChatAppearanceFilter',
					'entityId' => 'im-recent-v2',
					'className' => DiscussInChatAppearanceFilter::class,
				],
			],

			'entities' => [
				[
					'entityId' => 'address_book',
					'provider' => [
						'moduleId' => 'mail',
						'className' => AddressBookProvider::class,
					],
				],
				[
					'entityId' => 'mail_crm_recipient',
					'provider' => [
						'moduleId' => 'mail',
						'className' => MailCrmRecipientProvider::class,
					],
				],
				[
					'entityId' => 'mail_mailbox',
					'provider' => [
						'moduleId' => 'mail',
						'className' => MailboxProvider::class,
					],
				],
				[
					'entityId' => 'mail-massconnect-user',
					'substitutes' => 'user',
					'provider' => [
						'moduleId' => 'mail',
						'className' => MassConnectUserProvider::class,
					],
				],
			],
		],
		'readonly' => true,
	],
	'messenger' => [
		'value' => [
			'brokers' => [
				'mail_classify_db' => [
					'type' => \Bitrix\Main\Messenger\Internals\Broker\DbBroker::TYPE_CODE,
					'params' => [
						'table' => MessengerTable::class,
						'module' => 'mail',
					],
				],
			],
			'queues' => [
				'mail_access_notification' => [
					'handler' => MailboxAccessNotificationReceiver::class,
				],
				'mail_migration_notification' => [
					'handler' => MailboxMigrationNotificationReceiver::class,
				],
				'mail_orphan_autodisconnect_notification' => [
					'handler' => OrphanedMailboxAutoDisconnectNotificationReceiver::class,
				],
				'mail_connection_request_chats_repair' => [
					'handler' => RepairConnectionRequestChatsReceiver::class,
				],
				// The only queue of the module on a table of its own: it is the one whose volume
				// grows with the incoming mail flow, and the one whose pass makes an outgoing
				// HTTP call per message. The notification queues above stay on the shared table.
				'mail_message_classify' => [
					'broker' => 'mail_classify_db',
					'handler' => ClassifyMailMessageReceiver::class,
					'limit' => 10,
					'total_processing_limit' => 500,
				],
			],
		],
		'readonly' => true,
	],
	'rest' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Mail\\Infrastructure\\Rest\\Controller',
		],
	],
	'aiassistant.marta' => [
		'value' => [
			'agents' => [
				Bitrix\Mail\Integration\AiAssistant\Service\Agent\MailboxMessageAgent::class,
			],
			'toolSets' => [
				Bitrix\Mail\Integration\AiAssistant\Service\ToolSet\MailboxToolSet::class,
			],
		],
		'readonly' => true,
	],
);
