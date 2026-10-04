<?php

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp\OpenAppLayoutService;
use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;
use Bitrix\Vibecodeconnector\Internal\Integration\Immobile\VibeButtonAvailability;
use Bitrix\Vibecodeconnector\Internal\Integration\Intranet\IntranetGate;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\MainPortalFieldsProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\BotWebhookGateway;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\PortalNetworkId;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\IncomingJwtVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Bot\BotService;
use Bitrix\Vibecodeconnector\Internal\Service\Bot\TokenGenerator;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppLayoutRenderer;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppPayloadBuilder;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Sharing\CatalogSharingResponseMapper;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Sharing\CatalogSharingService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Vibecode\CatalogItemSender;
use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\CloudSharedKeyLog;
use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\IncomingJwtLog;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver\UserEventReceiver;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver\UserListPullReceiver;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Storage\VibecodeMessengerMessageTable;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\CloudEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointUrlGuard;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudKeySourceSettings;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyProvisioner;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyRefresher;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyRefreshThrottle;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyStore;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PairingKeyRefresher;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PublicKeyFetcherFactory;
use Bitrix\Vibecodeconnector\Internal\Service\Registration\PairingSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource\Policy as PermissionSourcePolicy;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource\Settings as PermissionSourceSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Registration\RegistrationService;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserAttributesResolver;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventPublisher;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventTargetProvider;
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
			],
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			ModuleOptions::class => [
				'className' => ModuleOptions::class,
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
			CloudSharedKeyRefreshThrottle::class => [
				'constructor' => static function () {
					return new CloudSharedKeyRefreshThrottle(
						ServiceLocator::getInstance()->get(ModuleOptions::class),
					);
				},
			],
			CloudSharedKeyProvisioner::class => [
				'constructor' => static function () {
					return new CloudSharedKeyProvisioner(
						ServiceLocator::getInstance()->get(CloudSharedKeyRefresher::class),
						ServiceLocator::getInstance()->get(CloudSharedKeyRefreshThrottle::class),
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
			OpenAppLayoutRenderer::class => [
				'className' => OpenAppLayoutRenderer::class,
			],
			OpenAppLayoutService::class => [
				'constructor' => static function () {
					return new OpenAppLayoutService(
						ServiceLocator::getInstance()->get(OpenAppSettings::class),
						new \Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository(),
						ServiceLocator::getInstance()->get(OpenAppPayloadBuilder::class),
						ServiceLocator::getInstance()->get(OpenAppLayoutRenderer::class),
						new \Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes(),
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
