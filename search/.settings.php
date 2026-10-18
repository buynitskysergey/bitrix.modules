<?php

return [
	'messenger' => [
		'value' => [
			'queues' => [
				'search_sync_perm' => [
					'handler' => \Bitrix\Search\SyncPermissionReceiver::class,
					'limit' => 1,
					'retry_strategy' => [
						'delay' => 60,
					],
				],
				'search_sync_content' => [
					'handler' => \Bitrix\Search\SyncIndexItemReceiver::class,
					'limit' => 1,
					'retry_strategy' => [
						'delay' => 60,
					],
				],
			],
		],
		'readonly' => true,
	],
	'console' => [
		'value' => [
			'commands' => [
				\Bitrix\Search\Cli\ReindexCommand::class,
			],
		],
		'readonly' => true,
	],
];
