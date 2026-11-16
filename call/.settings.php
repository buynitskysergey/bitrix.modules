<?php

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Call\\Controller',
			'restIntegration' => [
				'enabled' => true,
			],
		],
		'readonly' => true,
	],
	'rest' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Call\\Controller',
		],
	],
	'feature-flags' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Call\\Public\\Features',
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			\Bitrix\Call\Service\FollowUpReader::class => [
				'className' => \Bitrix\Call\Service\FollowUpReader::class,
			],
			'call.followup.reader' => [
				'className' => \Bitrix\Call\Service\FollowUpReader::class,
			],
		],
		'readonly' => true,
	],
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'call-log',
					'provider' => [
						'moduleId' => 'call',
						'className' => \Bitrix\Call\Integration\UI\EntitySelector\CallLogProvider::class,
					],
				],
			],
			'extensions' => ['im.entity-selector'],
		],
		'readonly' => true,
	],
];