<?php

return [
	'controllers' => [
		'value' => [
			'namespaces' => [
				'\\Bitrix\\Im\\V2\\Controller' => 'v2',
			],
			'defaultNamespace' => '\\Bitrix\\Im\\Controller',
			'restIntegration' => [
				'enabled' => true,
				'scopes' => ['im.import']
			]
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			'Im.Messenger' => [
				'className' => \Bitrix\Im\V2\Service\Messenger::class,
			],
			'Im.PullSender' => [
				'className' => \Bitrix\Im\V2\Pull\Sender::class,
			],
			'Im.Services.Message' => [
				'className' => \Bitrix\Im\Services\Message::class,
			],
			'Im.Services.MessageParam' => [
				'className' => \Bitrix\Im\Services\MessageParam::class,
			],
			'Im.Services.Promotion' => [
				'className' => \Bitrix\Im\V2\Promotion\Promotion::class,
			],
			'Im.AccessService' => [
				'className' => \Bitrix\Im\V2\Service\AccessService::class,
			],
			'Im.GuestInviteSmsTransport' => [
				'className' => \Bitrix\Im\V2\SharingLink\Transport\DefaultGuestInviteSmsTransport::class,
			],
			'Im.GuestInviteEmailTransport' => [
				'className' => \Bitrix\Im\V2\SharingLink\Transport\DefaultGuestInviteEmailTransport::class,
			],
		],
		'readonly' => true,
	],
	'ui.entity-selector' => [
		'value' => [
			'filters' => [
				[
					'id' => 'im.userDataFilter',
					'entityId' => 'user',
					'className' => \Bitrix\Im\Integration\UI\EntitySelector\UserDataFilter::class,
				],
				[
					'id' => 'im.departmentDataFilter',
					'entityId' => 'department',
					'className' => \Bitrix\Im\Integration\UI\EntitySelector\DepartmentDataFilter::class,
				],
				[
					'id' => 'im.chatOnlyDataFilter',
					'entityId' => 'im-chat-only',
					'className' => \Bitrix\Im\V2\Integration\UI\EntitySelector\ChannelAndChatDataFilter::class,
				],
			],
			'entities' => [
				[
					'entityId' => 'im-bot',
					'provider' => [
						'moduleId' => 'im',
						'className' => \Bitrix\Im\Integration\UI\EntitySelector\BotProvider::class,
					],
				],
				[
					'entityId' => 'im-chat',
					'provider' => [
						'moduleId' => 'im',
						'className' => \Bitrix\Im\Integration\UI\EntitySelector\ChatProvider::class,
					],
				],
				[
					'entityId' => 'im-chat-user',
					'provider' => [
						'moduleId' => 'im',
						'className' => \Bitrix\Im\Integration\UI\EntitySelector\ChatUserProvider::class,
					],
				],
				[
					'entityId' => 'im-recent',
					'provider' => [
						'moduleId' => 'im',
						'className' => \Bitrix\Im\Integration\UI\EntitySelector\RecentChatProvider::class,
					],
				],
				[
					'entityId' => 'im-recent-v2',
					'provider' => [
						'moduleId' => 'im',
						'className' => \Bitrix\Im\V2\Integration\UI\EntitySelector\RecentProvider::class,
					],
				],
				[
					'entityId' => 'im-chat-only',
					'provider' => [
						'moduleId' => 'im',
						'className' => \Bitrix\Im\V2\Integration\UI\EntitySelector\ChatOnlyProvider::class,
					],
				],
			],
			'extensions' => ['im.entity-selector'],
		],
		'readonly' => true,
	],
	'messenger' => [
		'value' => [
			'queues' => [
				/** @see \Bitrix\Im\V2\Async\QueueId */
				'im_department_tree_sync' => [
					'handler' => \Bitrix\Im\V2\Chat\Tree\Async\DepartmentTreeSyncReceiver::class,
					'retry_strategy' => [
						'max_retries' => 5,
						'delay' => 5,
						'multiplier' => 3,
					],
				],
			],
		],
		'readonly' => true,
	],
	'aiassistant.marta' => [
		'value' => [
			'toolSets' => [
				Bitrix\Im\V2\Integration\AiAssistant\ToolSets\ImToolSet::class,
			],
		],
		'readonly' => true,
	],
];
