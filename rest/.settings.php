<?php

declare(strict_types=1);

use \Bitrix\Rest;

return [
	'rest' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Rest\\V3\\Realisation\\Controller',
			'namespaces' => [
				'\\Bitrix\\Rest\\Infrastructure\\Rest\\Controller',
			],
			'routes' => [
				'documentation' => 'rest.documentation.openApi',
				'scopes' => 'rest.scope.list',
			],
			'documentation' => [
				'methods' => [
					'batch' => \Bitrix\Rest\V3\Documentation\BatchMethodProvider::class,
				],
			],
		]
	],
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Rest\\Controller',
			'restIntegration' => [
				'enabled' => true,
				'hideModuleScope' => true,
				'scopes' => [
					'appform',
					'configuration.import',
					'user',
				],
			],
		],
		'readonly' => true
	],
	'services' => [
		'value' => [
			Rest\Public\Service\VibePlusMarketApplicationLimitProvider::class => [
				'className' => Rest\Public\Service\VibePlusMarketApplicationLimitProvider::class,
			],
			'rest.service.vibe_plus.tariff_access' => [
				'className' => Rest\Internal\Service\VibePlus\TariffAccessService::class,
			],
			'rest.service.apauth.password' => [
				'className' => \Bitrix\Rest\Service\APAuth\PasswordService::class,
			],
			'rest.service.apauth.permission' => [
				'className' => \Bitrix\Rest\Service\APAuth\PermissionService::class,
			],
			'rest.service.app' => [
				'constructor' => function () {
					return new Rest\Service\AppService(
						new Rest\Internal\Repository\Application\AppRepository()
					);
				},
			],
			'rest.service.integration' => [
				'constructor' => function () {
					return new \Bitrix\Rest\Service\IntegrationService(
						new \Bitrix\Rest\Repository\IntegrationRepository(
							new Bitrix\Rest\Model\Mapper\Integration()
						)
					);
				},
			],
			'rest.repository.app' => [
				'constructor' => static function () {
					return new Rest\Internal\Repository\Application\AppRepository();
				},
			],
			Rest\Internal\Service\Application\ApplicationInstaller::class => [
				'constructor' => static function () {
					return new Rest\Internal\Service\Application\ApplicationInstaller(
						\Bitrix\Main\DI\ServiceLocator::getInstance()->get('rest.repository.app'),
					);
				},
			],
			'rest.repository.integration' => [
				'constructor' => static function () {
					return new \Bitrix\Rest\Repository\IntegrationRepository(
						new \Bitrix\Rest\Model\Mapper\Integration()
					);
				},
			],
			Rest\V3\Idempotency\IdempotencyService::class => [
				'constructor' => static function () {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return new Rest\V3\Idempotency\IdempotencyService(
						$locator->get(\Bitrix\Main\Data\Storage\PersistentStorageInterface::class),
						$locator->get(Rest\V3\Schema\SchemaManager::class),
					);
				},
			],
			Rest\V3\Idempotency\IdempotencyKeyResolver::class => [
				'className' => Rest\V3\Idempotency\IdempotencyKeyResolver::class,
			],
		],
		'readonly' => true,
	],
	'messenger' => [
		'value' => [
			'queues' => [
				'rest.deferred_batch' => [
					'handler' => Bitrix\Rest\Internal\Service\Messenger\DeferredBatch\Receiver::class,
					'limit' => 5,
					'total_processing_limit' => 20,
					'retry_strategy' => [
						'max_retries' => 3,
						'delay' => 30,
						'multiplier' => 2,
						'max_delay' => 300,
					],
				],
			],
		],
		'readonly' => true,
	],
];
