<?php

use Bitrix\Disk\Bitrix24Disk\SubscriberManager;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\DocumentHandlersManager;
use Bitrix\Disk\Document\Flipchart\DualMode;
use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Disk\Document\Vibeoffice;
use Bitrix\Disk\Internal\Entity\CustomServers\OnlyOfficeCustomServer;
use Bitrix\Disk\Internal\Entity\CustomServers\R7CustomServer;
use Bitrix\Disk\Internal\Interface\CustomServerDataRepositoryInterface;
use Bitrix\Disk\Internal\Repository\BitrixOrmDocumentRestrictionLogRepository;
use Bitrix\Disk\Internal\Repository\BitrixOrmDocumentSessionRepository;
use Bitrix\Disk\Internal\Repository\CustomServerDataOptionRepository;
use Bitrix\Disk\Internal\Repository\ExternalLinkBitrixOrmRepository;
use Bitrix\Disk\Internal\Repository\Interface\DocumentRestrictionLogRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\DocumentSessionRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\ExternalLinkRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\LimitEncounterCounterRepositoryInterface;
use Bitrix\Disk\Internal\Repository\LimitEncounterCounterPersistentStorageRepository;
use Bitrix\Disk\Internal\Service\CustomServerConfig;
use Bitrix\Disk\Internal\Service\VersionMatcher\DottedMatcher;
use Bitrix\Disk\Internal\Service\VersionMatcher\Matcher;
use Bitrix\Disk\Internals\DeletedLogManager;
use Bitrix\Disk\Internals\DeletionNotifyManager;
use Bitrix\Disk\Internals\Runtime\StorageRuntimeCache;
use Bitrix\Disk\QuickAccess;
use Bitrix\Disk\QuickAccess\Config\ConfigInterface;
use Bitrix\Disk\QuickAccess\FileInfo\DiskProvider;
use Bitrix\Disk\QuickAccess\FileInfo\MainProvider;
use Bitrix\Disk\QuickAccess\FileInfo\ProviderFactory;
use Bitrix\Disk\RecentlyUsedManager;
use Bitrix\Disk\Rest\RestManager;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Search\IndexManager;
use Bitrix\Disk\Uf\UserFieldManager;
use Bitrix\Disk\UrlManager;
use Bitrix\Disk\TrackedObjectManager;
use Bitrix\Main\Localization\Loc;

$supportedOnlyOfficeVersions = [
	'9.2.*.*',
];

$supportedR7Versions = [
	'2025.4.*.*',
	'2025.3.*.*',
];

$supportedOnlyOfficeAndR7Versions = array_merge($supportedOnlyOfficeVersions, $supportedR7Versions);

return [
	'controllers' => [
		'value' => [
			'namespaces' => [
				'\\Bitrix\\Disk\\Controller' => 'api',
			],
			'defaultNamespace' => '\\Bitrix\\Disk\\Controller',
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			'disk.onlyofficeConfiguration' => [
				'className' => OnlyOffice\Configuration::class,
			],
			'disk.vibeofficeConfiguration' => [
				'className' => Vibeoffice\Configuration::class,
			],
			'disk.urlManager' => [
				'className' => UrlManager::class,
			],
			'disk.storageRuntimeCache' => [
				'className' => StorageRuntimeCache::class,
			],
			'disk.documentHandlersManager' => [
				'className' => DocumentHandlersManager::class,
				'constructorParams' => static function() {
					global $USER;

					return [
						'userId' => $USER,
					];
				},
			],
			'disk.rightsManager' => [
				'className' => RightsManager::class,
			],
			'disk.ufManager' => [
				'className' => UserFieldManager::class,
			],
			'disk.indexManager' => [
				'className' => IndexManager::class,
			],
			'disk.recentlyUsedManager' => [
				'className' => RecentlyUsedManager::class,
			],
			'disk.restManager' => [
				'className' => RestManager::class,
			],

			'disk.subscriberManager' => [
				'className' => SubscriberManager::class,
			],
			'disk.deletedLogManager' => [
				'className' => DeletedLogManager::class,
			],

			'disk.deletionNotifyManager' => [
				'className' => DeletionNotifyManager::class,
			],
			'disk.trackedObjectManager' => [
				'className' => TrackedObjectManager::class,
			],
			DocumentRestrictionLogRepositoryInterface::class => [
				'className' => BitrixOrmDocumentRestrictionLogRepository::class,
			],
			DocumentSessionRepositoryInterface::class => [
				'className' => BitrixOrmDocumentSessionRepository::class,
			],
			ConfigInterface::class => [
				'className' => QuickAccess\Configuration::class,
			],
			ProviderFactory::class => [
				'constructor' => static function() {
					$providerFactory = new ProviderFactory();
					$providerFactory->register(DiskProvider::class);
					$providerFactory->register(MainProvider::class);


					return $providerFactory;
				},
			],
			LimitEncounterCounterRepositoryInterface::class => [
				'className' => LimitEncounterCounterPersistentStorageRepository::class,
			],
			CustomServerDataRepositoryInterface::class => [
				'className' => CustomServerDataOptionRepository::class,
			],
			CustomServerConfig::class => [
				'className' => CustomServerConfig::class,
				'singleton' => false,
			],
			Matcher::class => [
				'constructor' => static function () {
					return new Matcher(
						config: Configuration::getVersionMatchers(),
					);
				},
			],
			ExternalLinkRepositoryInterface::class => [
				'className' => ExternalLinkBitrixOrmRepository::class,
			],
			DualMode\BoardApiServiceFactory::class => [
				'className' => DualMode\DefaultBoardApiServiceFactory::class,
			],
		],
		'readonly' => true,
	],
	'b24documents' => [
		'value' => [
			'serverListEndpoint' => 'https://oo-proxy.bitrix.info/settings/config.json',
		],
		'readonly' => true,
	],
	'ui.uploader' => [
		'value' => [
			'allowUseControllers' => true,
		],
		'readonly' => true,
	],
	'boards' => [
		'value' => [
			'client_token_header_lookup' => 'X-Permissions',
			'api_host' => 'https://flip-backend',
			'jwt_ttl' => 30,
			'app_url' => 'https://flip-backend/app',
			'save_delta_time' => 30,
			'save_probability_coef' => 0.1,
			'webhook_url' => '/bitrix/services/main/ajax.php?action=disk.integration.flipchart.webhook',
		],
		'readonly' => true,
	],
	'promo' => [
		'value' => [
			'cloud_tariff_groups' => [
				'extendable' => [
					'demo',
					'nfr',
					'std',
					'pro100',
					'ent250',
					'ent500',
				],
				'large_enterprise' => [
					'ent1000',
					'ent2000',
					'ent3000',
					'ent4000',
					'ent5000',
					'ent6000',
					'ent7000',
					'ent8000',
					'ent9000',
					'ent10000',
					'entholding1000',
					'entholding2000',
					'entholding3000',
					'entholding4000',
					'entholding5000',
					'entholding6000',
					'entholding7000',
					'entholding8000',
					'entholding9000',
					'entholding10000',
				],
			],
		],
		'readonly' => true,
	],
	'customServers' => [
		'value' => [
			'r7' => [
				'className' => R7CustomServer::class,
				'type' => 'r7',
				'isEnabled' => true,
				'title' => Loc::getMessage('DISK_CUSTOM_SERVER_R7_TITLE'),
				'adminTemplates' => [
					'form' => __DIR__ . '/admin/templates/r7/form.php',
				],
				'supportedVersions' => [
					'type' => 'dotted',
					'values' => $supportedOnlyOfficeAndR7Versions,
				],
				'maxFileSize' => 104_857_600,
				'availableRegions' => [
					'ru',
					'by',
				],
			],
			'onlyoffice' => [
				'className' => OnlyOfficeCustomServer::class,
				'type' => 'onlyoffice',
				'isEnabled' => false,
				'title' => Loc::getMessage('DISK_CUSTOM_SERVER_ONLYOFFICE_TITLE'),
				'adminTemplates' => [
					'form' => __DIR__ . '/admin/templates/onlyoffice/form.php',
				],
				'supportedVersions' => [
					'type' => 'dotted',
					'values' => $supportedOnlyOfficeAndR7Versions,
				],
				'maxFileSize' => 104_857_600,
				'unavailableRegions' => [
					'ru',
					'by',
				],
			],
		],
		'readonly' => true,
	],
	'customServersRegions' => [
		'value' => [
			'ru',
			'by',
		],
		'readonly' => true,
	],
	'versionMatchers' => [
		'value' => [
			'dotted' => DottedMatcher::class,
		],
		'readonly' => true,
	],
	'messenger' => [
		'value' => [
			'queues' => [
				'download_board' => [
					'handler' => \Bitrix\Disk\Document\Flipchart\Messenger\DownloadBoardReceiver::class,
				],
			],
		],
		'readonly' => true,
	],
];
