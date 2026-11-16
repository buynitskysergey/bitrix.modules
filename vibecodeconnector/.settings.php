<?php

use Bitrix\Main\Config\Feature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp\OpenAppLayoutService;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Embed\EmbedOpenLayoutService;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Embed\SignedFormRenderer;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\HumanResourcesStructureApi;
use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusPolicy;
use Bitrix\Vibecodeconnector\Internal\Integration\HumanResources\CompatOrgStructureGateway;
use Bitrix\Vibecodeconnector\Internal\Integration\HumanResources\OrgStructureGateway;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\EventHandler\AttachCatalogBlock;
use Bitrix\Vibecodeconnector\Internal\Integration\Immobile\VibeButtonAvailability;
use Bitrix\Vibecodeconnector\Internal\Integration\Intranet\IntranetGate;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\MainPortalFieldsProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\BotWebhookGateway;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\PortalNetworkId;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\IncomingJwtVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Availability\PortalReadiness;
use Bitrix\Vibecodeconnector\Internal\Service\Bot\BotService;
use Bitrix\Vibecodeconnector\Internal\Service\Bot\TokenGenerator;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\AccessChangeHandler;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatBackfillService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMaintenanceService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMembershipService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMessageService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatOnDemandService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Embedding\EmbeddingAccessCodes;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Launch\LaunchService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\CatalogItemOpenability;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppPayloadBuilder;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Preview\BlackholeAppLinkDetector;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Preview\CatalogItemPreviewService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Sharing\CatalogSharingResponseMapper;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Sharing\CatalogSharingService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Vibecode\CatalogItemSender;
use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\CloudSharedKeyLog;
use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\IncomingJwtLog;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedCoworkAdminSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedCreateGuard;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedCreateSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Embed\EmbedOpenPayloadBuilder;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\CloudEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointUrlGuard;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver\AppChatDeletionReceiver;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver\AppChatMembershipReceiver;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver\BalancePullReceiver;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver\UserEventReceiver;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver\UserListPullReceiver;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Storage\VibecodeMessengerMessageTable;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource\Policy as PermissionSourcePolicy;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource\Settings as PermissionSourceSettings;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudKeySourceSettings;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyProvisioner;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\KeyRefreshThrottle;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyRefresher;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyStore;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PairingKeyRefresher;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PublicKeyFetcherFactory;
use Bitrix\Vibecodeconnector\Internal\Service\Registration\PairingSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Registration\RegistrationService;
use Bitrix\Vibecodeconnector\Internal\Service\Rest\AccessGrantService;
use Bitrix\Vibecodeconnector\Internal\Service\Rest\OriginTokenVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserAttributesResolver;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventPublisher;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventTargetProvider;
use Bitrix\Vibecodeconnector\Public\Provider\CatalogItemEmbeddingOwnerProvider;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Vibecodeconnector\\Infrastructure\\Controller',
		],
		'readonly' => true,
	],
	'rest' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Vibecodeconnector\\Infrastructure\\Rest\\Controller',
		],
		'readonly' => true,
	],
	'rest.embedding.ownerProviders' => [
		'value' => [
			'catalog_item' => CatalogItemEmbeddingOwnerProvider::class,
		],
		'readonly' => true,
	],
	'messenger' => [
		'value' => [
			'brokers' => [
				'vibecodeconnector' => [
					'type' => 'db',
					'params' => [
						'module' => 'vibecodeconnector',
						'table' => VibecodeMessengerMessageTable::class,
					],
				],
			],
			'queues' => [
				'vibecodeconnector.balance_pull' => [
					'handler' => BalancePullReceiver::class,
					'broker' => 'vibecodeconnector',
					'limit' => 1,
					'total_processing_limit' => 10,
					'retry_strategy' => [
						'max_retries' => 10,
						'delay' => 5,
						'multiplier' => 2,
						'max_delay' => 86400,
					],
				],
				'vibecodeconnector.user_list_pull' => [
					'handler' => UserListPullReceiver::class,
					'broker' => 'vibecodeconnector',
					'limit' => 1,
					'total_processing_limit' => 10,
				],
				'vibecodeconnector.user_event' => [
					'handler' => UserEventReceiver::class,
					'broker' => 'vibecodeconnector',
					'limit' => 20,
					'total_processing_limit' => 100,
				],
				'vibecodeconnector.app_chat_deletion' => [
					'handler' => AppChatDeletionReceiver::class,
					'broker' => 'vibecodeconnector',
					'limit' => 1,
					'total_processing_limit' => 100,
					'retry_strategy' => [
						'max_retries' => 10,
						'delay' => 60,
						'multiplier' => 2,
						'max_delay' => 21600,
					],
				],
				'vibecodeconnector.app_chat_membership' => [
					'handler' => AppChatMembershipReceiver::class,
					'broker' => 'vibecodeconnector',
					'limit' => 4,
					'total_processing_limit' => 100,
					'retry_strategy' => [
						'max_retries' => 10,
						'delay' => 60,
						'multiplier' => 2,
						'max_delay' => 21600,
					],
				],
			],
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			BalancePullReceiver::class => [
				'className' => BalancePullReceiver::class,
			],
			AppChatBackfillService::class => [
				'constructor' => static function () {
					return new AppChatBackfillService(
						membershipService: ServiceLocator::getInstance()->get(AppChatMembershipService::class),
					);
				},
			],
			AppChatMaintenanceService::class => [
				'constructor' => static function () {
					return new AppChatMaintenanceService(
						backfillService: ServiceLocator::getInstance()->get(AppChatBackfillService::class),
					);
				},
			],
			ModuleOptions::class => [
				'className' => ModuleOptions::class,
			],
			AppChatDeletionReceiver::class => [
				'className' => AppChatDeletionReceiver::class,
			],
			AppChatMessageService::class => [
				'className' => AppChatMessageService::class,
			],
			AppChatService::class => [
				'constructor' => static function () {
					return new AppChatService(
						messageService: ServiceLocator::getInstance()->get(AppChatMessageService::class),
					);
				},
			],
			AppChatMembershipService::class => [
				'className' => AppChatMembershipService::class,
			],
			AppChatOnDemandService::class => [
				'constructor' => static function () {
					return new AppChatOnDemandService(
						appChatService: ServiceLocator::getInstance()->get(AppChatService::class),
						membershipService: ServiceLocator::getInstance()->get(AppChatMembershipService::class),
					);
				},
			],
			AppChatMembershipReceiver::class => [
				'constructor' => static function () {
					return new AppChatMembershipReceiver(
						membershipService: ServiceLocator::getInstance()->get(AppChatMembershipService::class),
					);
				},
			],
			AccessChangeHandler::class => [
				'constructor' => static function () {
					return new AccessChangeHandler(
						ServiceLocator::getInstance()->get(AppChatMembershipService::class),
					);
				},
			],
			LaunchService::class => [
				'constructor' => static function () {
					return new LaunchService(
						appChatOnDemandService: ServiceLocator::getInstance()->get(AppChatOnDemandService::class),
					);
				},
			],
			UserEventTargetProvider::class => [
				'constructor' => static function () {
					return new UserEventTargetProvider(
						pairingRepository: ServiceLocator::getInstance()->get(PairingRepository::class),
						cloudSharedVerifier: ServiceLocator::getInstance()->get(CloudSharedVerifier::class),
						cloudEndpointProvider: ServiceLocator::getInstance()->get(CloudEndpointProvider::class),
					);
				},
			],
			UserEventReceiver::class => [
				'constructor' => static function () {
					return new UserEventReceiver(
						targetProvider: ServiceLocator::getInstance()->get(UserEventTargetProvider::class),
					);
				},
			],
			UserEventPublisher::class => [
				'constructor' => static function () {
					return new UserEventPublisher(
						targetProvider: ServiceLocator::getInstance()->get(UserEventTargetProvider::class),
					);
				},
			],
			UserAttributesResolver::class => [
				'className' => UserAttributesResolver::class,
			],
			OrgStructureGateway::class => [
				'constructor' => static function () {
					return Feature::isEnabled(HumanResourcesStructureApi::class)
						? new OrgStructureGateway()
						: new CompatOrgStructureGateway()
					;
				},
			],
			UserListPullReceiver::class => [
				'constructor' => static function () {
					return new UserListPullReceiver(
						targetProvider: ServiceLocator::getInstance()->get(UserEventTargetProvider::class),
					);
				},
			],
			PortalNetworkId::class => [
				'className' => PortalNetworkId::class,
			],
			NetworkService::class => [
				'constructor' => static function () {
					return new NetworkService(
						ServiceLocator::getInstance()->get(PortalNetworkId::class),
					);
				},
			],
			BaseEndpointProvider::class => [
				'constructor' => static function () {
					return new BaseEndpointProvider(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			CloudEndpointProvider::class => [
				'constructor' => static function () {
					return new CloudEndpointProvider(
						ServiceLocator::getInstance()->get(BaseEndpointProvider::class),
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			PairingRepository::class => [
				'className' => PairingRepository::class,
			],
			PairingSettings::class => [
				'constructor' => static function () {
					return new PairingSettings(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			EndpointUrlGuard::class => [
				'className' => EndpointUrlGuard::class,
			],
			CloudSharedKeyStore::class => [
				'constructor' => static function () {
					return new CloudSharedKeyStore(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			CloudSharedVerifier::class => [
				'constructor' => static function () {
					return new CloudSharedVerifier(
						ServiceLocator::getInstance()->get(CloudSharedKeyStore::class),
						ServiceLocator::getInstance()->get(CloudSharedKeyProvisioner::class),
						ServiceLocator::getInstance()->get(PortalNetworkId::class),
					);
				},
			],
			CloudKeySourceSettings::class => [
				'constructor' => static function () {
					return new CloudKeySourceSettings(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			PublicKeyFetcherFactory::class => [
				'className' => PublicKeyFetcherFactory::class,
			],
			CloudSharedKeyRefresher::class => [
				'constructor' => static function () {
					return new CloudSharedKeyRefresher(
						ServiceLocator::getInstance()->get(CloudEndpointProvider::class),
						ServiceLocator::getInstance()->get(CloudKeySourceSettings::class),
						ServiceLocator::getInstance()->get(PublicKeyFetcherFactory::class),
						ServiceLocator::getInstance()->get(CloudSharedKeyStore::class),
					);
				},
			],
			KeyRefreshThrottle::class => [
				'constructor' => static function () {
					return new KeyRefreshThrottle(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			CloudSharedKeyProvisioner::class => [
				'constructor' => static function () {
					return new CloudSharedKeyProvisioner(
						ServiceLocator::getInstance()->get(CloudSharedKeyRefresher::class),
						ServiceLocator::getInstance()->get(KeyRefreshThrottle::class),
						ServiceLocator::getInstance()->get(CloudSharedKeyLog::class),
					);
				},
			],
			PairingKeyRefresher::class => [
				'constructor' => static function () {
					return new PairingKeyRefresher(
						ServiceLocator::getInstance()->get(PairingRepository::class),
						ServiceLocator::getInstance()->get(PublicKeyFetcherFactory::class),
						ServiceLocator::getInstance()->get(PairingSettings::class),
					);
				},
			],
			IncomingJwtVerifier::class => [
				'constructor' => static function () {
					return new IncomingJwtVerifier(
						ServiceLocator::getInstance()->get(PairingRepository::class),
						ServiceLocator::getInstance()->get(CloudSharedVerifier::class),
						ServiceLocator::getInstance()->get(PairingKeyRefresher::class),
					);
				},
			],
			RegistrationService::class => [
				'constructor' => static function () {
					return new RegistrationService(
						ServiceLocator::getInstance()->get(PairingRepository::class),
						ServiceLocator::getInstance()->get(PairingSettings::class),
						ServiceLocator::getInstance()->get(CloudSharedVerifier::class),
						urlGuard: ServiceLocator::getInstance()->get(EndpointUrlGuard::class),
					);
				},
			],
			TokenGenerator::class => [
				'className' => TokenGenerator::class,
			],
			BotService::class => [
				'constructor' => static function () {
					return new BotService(
						ServiceLocator::getInstance()->get(BaseEndpointProvider::class),
						ServiceLocator::getInstance()->get(TokenGenerator::class),
						new BotWebhookGateway(),
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			CatalogItemSender::class => [
				'constructor' => static function () {
					return new CatalogItemSender(
						ServiceLocator::getInstance()->get(BaseEndpointProvider::class),
						ServiceLocator::getInstance()->get(PairingRepository::class),
						ServiceLocator::getInstance()->get(NetworkService::class),
						ServiceLocator::getInstance()->get(CatalogSharingResponseMapper::class),
					);
				},
			],
			CatalogSharingResponseMapper::class => [
				'className' => CatalogSharingResponseMapper::class,
			],
			CatalogSharingService::class => [
				'constructor' => static function () {
					return new CatalogSharingService(
						new \Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository(),
						ServiceLocator::getInstance()->get(CatalogItemSender::class),
					);
				},
			],
			CatalogItemPreviewService::class => [
				'constructor' => static function () {
					return new CatalogItemPreviewService(
						new \Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository(),
						ServiceLocator::getInstance()->get(AvailabilityService::class),
					);
				},
			],
			BlackholeAppLinkDetector::class => [
				'constructor' => static function () {
					return new BlackholeAppLinkDetector(
						new \Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository(),
						ServiceLocator::getInstance()->get(PairingRepository::class),
						ServiceLocator::getInstance()->get(CloudEndpointProvider::class),
						ServiceLocator::getInstance()->get(CloudSharedVerifier::class),
					);
				},
			],
			AttachCatalogBlock::class => [
				'constructor' => static function () {
					return new AttachCatalogBlock(
						ServiceLocator::getInstance()->get(CatalogItemPreviewService::class),
						ServiceLocator::getInstance()->get(BlackholeAppLinkDetector::class),
					);
				},
			],
			OpenAppSettings::class => [
				'constructor' => static function () {
					return new OpenAppSettings(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			MainPortalFieldsProvider::class => [
				'className' => MainPortalFieldsProvider::class,
			],
			OpenAppPayloadBuilder::class => [
				'constructor' => static function () {
					return new OpenAppPayloadBuilder(
						ServiceLocator::getInstance()->get(BaseEndpointProvider::class),
						ServiceLocator::getInstance()->get(PairingRepository::class),
						ServiceLocator::getInstance()->get(MainPortalFieldsProvider::class),
						ServiceLocator::getInstance()->get(NetworkService::class),
					);
				},
			],
			EmbedCreateSettings::class => [
				'constructor' => static function () {
					return new EmbedCreateSettings(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			EmbedCoworkAdminSettings::class => [
				'constructor' => static function () {
					return new EmbedCoworkAdminSettings(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			EmbedCreateGuard::class => [
				'constructor' => static function () {
					return new EmbedCreateGuard(
						ServiceLocator::getInstance()->get(AvailabilityService::class),
						ServiceLocator::getInstance()->get(VibePlusPolicy::class),
					);
				},
			],
			EmbedOpenPayloadBuilder::class => [
				'constructor' => static function () {
					return new EmbedOpenPayloadBuilder(
						ServiceLocator::getInstance()->get(BaseEndpointProvider::class),
						ServiceLocator::getInstance()->get(CloudEndpointProvider::class),
						ServiceLocator::getInstance()->get(MainPortalFieldsProvider::class),
						ServiceLocator::getInstance()->get(NetworkService::class),
						ServiceLocator::getInstance()->get(UserAttributesResolver::class),
					);
				},
			],
			EmbedOpenLayoutService::class => [
				'constructor' => static function () {
					return new EmbedOpenLayoutService(
						ServiceLocator::getInstance()->get(EmbedCreateGuard::class),
						ServiceLocator::getInstance()->get(EmbedCreateSettings::class),
						ServiceLocator::getInstance()->get(EmbedOpenPayloadBuilder::class),
						new SignedFormRenderer('embed-create'),
						ServiceLocator::getInstance()->get(EmbedCoworkAdminSettings::class),
					);
				},
			],
			IconStorageService::class => [
				'className' => IconStorageService::class,
			],
			OpenAppLayoutService::class => [
				'constructor' => static function () {
					return new OpenAppLayoutService(
						ServiceLocator::getInstance()->get(OpenAppSettings::class),
						new \Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository(),
						ServiceLocator::getInstance()->get(OpenAppPayloadBuilder::class),
						accessCodes: new \Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes(),
						appChatOnDemandService: ServiceLocator::getInstance()->get(AppChatOnDemandService::class),
						launchService: ServiceLocator::getInstance()->get(LaunchService::class),
						iconStorage: ServiceLocator::getInstance()->get(IconStorageService::class),
					);
				},
			],
			CatalogItemEmbeddingOwnerProvider::class => [
				'constructor' => static function () {
					return new CatalogItemEmbeddingOwnerProvider(
						openAppSettings: ServiceLocator::getInstance()->get(OpenAppSettings::class),
						vibePlusPolicy: ServiceLocator::getInstance()->get(VibePlusPolicy::class),
						embeddingAccessCodes: ServiceLocator::getInstance()->get(EmbeddingAccessCodes::class),
						openability: ServiceLocator::getInstance()->get(CatalogItemOpenability::class),
					);
				},
			],
			VibeButtonAvailability::class => [
				'className' => VibeButtonAvailability::class,
			],
			AvailabilityService::class => [
				'constructor' => static function () {
					return new AvailabilityService(
						ServiceLocator::getInstance()->get(PairingRepository::class),
						ServiceLocator::getInstance()->get(CloudSharedVerifier::class),
						new IntranetGate(),
					);
				},
			],
			IncomingJwtLog::class => [
				'className' => IncomingJwtLog::class,
			],
			CloudSharedKeyLog::class => [
				'className' => CloudSharedKeyLog::class,
			],
			OriginTokenVerifier::class => [
				'constructor' => static function () {
					return new OriginTokenVerifier(
						ServiceLocator::getInstance()->get(IncomingJwtVerifier::class),
					);
				},
			],
			PortalReadiness::class => [
				'constructor' => static function () {
					return new PortalReadiness(
						ServiceLocator::getInstance()->get(CloudSharedVerifier::class),
						ServiceLocator::getInstance()->get(PairingRepository::class),
						ServiceLocator::getInstance()->get(ModuleOptions::class),
						ServiceLocator::getInstance()->get(VibeButtonAvailability::class),
					);
				},
			],
			AccessGrantService::class => [
				'constructor' => static function () {
					return new AccessGrantService(
						ServiceLocator::getInstance()->get(PortalReadiness::class),
						ServiceLocator::getInstance()->get(OriginTokenVerifier::class),
					);
				},
			],
			PermissionSourceSettings::class => [
				'constructor' => static function () {
					return new PermissionSourceSettings(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			PermissionSourcePolicy::class => [
				'constructor' => static function () {
					return new PermissionSourcePolicy(
						ServiceLocator::getInstance()->get(PermissionSourceSettings::class),
					);
				},
			],
		],
		'readonly' => true,
	],
];
