<?php
return [
	'controllers' => [
		'value' => [
			'namespaces' => [
				'\\Bitrix\\MessageService\\Infrastructure\\Controller' => 'api',
			],
			'defaultNamespace' => '\\Bitrix\\MessageService\\Controller',
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			'messageservice.public.ui.factory' => [
				'className' => \Bitrix\MessageService\Public\UI\Factory::class,
			],
			'messageservice.customTemplate.zoneCleanupService' => [
				'className' => \Bitrix\MessageService\Public\Service\CustomTemplate\CustomTemplateZoneCleanupService::class,
				'constructorParams' => static fn() => [
					\Bitrix\Main\DI\ServiceLocator::getInstance()->get(\Bitrix\MessageService\Internal\Repository\CustomTemplateRepository::class),
				],
			],
			\Bitrix\MessageService\Public\Service\CustomTemplate\CustomTemplateZoneRegistry::class => [
				'constructor' => static fn() => \Bitrix\MessageService\Public\Service\CustomTemplate\CustomTemplateZoneRegistry::load(),
			],
		],
		'readonly' => true,
	],
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'messageservice-custom-template',
					'provider' => [
						'moduleId' => 'messageservice',
						'className' => \Bitrix\MessageService\Integration\UI\EntitySelector\CustomTemplate\CustomTemplateProvider::class,
					],
				],
			],
		],
		'readonly' => true,
	],
];
