<?php

use Bitrix\Disk\Bitrix24Disk\SubscriberManager;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\DocumentHandlersManager;
use Bitrix\Disk\Document\Flipchart\DualMode;
use Bitrix\Disk\Document\OnlyOffice;
use Bitrix\Disk\Document\Vibeoffice;
use Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync\CapabilitiesGetMethodProvider;
use Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync\ChangesListMethodProvider;
use Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync\ContentGetMethodProvider;
use Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync\IndexGetMethodProvider;
use Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync\SessionCreateMethodProvider;
use Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync\SnapshotListMethodProvider;
use Bitrix\Disk\Infrastructure\Rest\V3\Documentation\Sync\StateCheckMethodProvider;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Integration\HumanResources\TeamDirectory;
use Bitrix\Disk\Integration\Ui\EntitySelector\FileProvider;
use Bitrix\Disk\Integration\Ui\EntitySelector\SpecialAccessProvider;
use Bitrix\Disk\Internal\Entity\CustomServers\OnlyOfficeCustomServer;
use Bitrix\Disk\Internal\Entity\CustomServers\R7CustomServer;
use Bitrix\Disk\Internal\Interface\CustomServerDataRepositoryInterface;
use Bitrix\Disk\Internal\Interface\TeamDirectoryInterface;
use Bitrix\Disk\Internal\Messenger\Audit\AuditReceiver;
use Bitrix\Disk\Internal\Model\AuditMessengerMessageTable;
use Bitrix\Disk\Internal\Repository\Audit\AuditEventRepository;
use Bitrix\Disk\Internal\Repository\Mapper\AuditEventMapper;
use Bitrix\Disk\Internal\Repository\BitrixOrmDocumentRestrictionLogRepository;
use Bitrix\Disk\Internal\Repository\BitrixOrmDocumentSessionRepository;
use Bitrix\Disk\Internal\Repository\BitrixOrmUserQuotaRepository;
use Bitrix\Disk\Internal\Repository\FormatPolicyRuleRepository;
use Bitrix\Disk\Internal\Repository\CustomServerDataOptionRepository;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadQueueRepository;
use Bitrix\Disk\Internal\Repository\ExternalLinkBitrixOrmRepository;
use Bitrix\Disk\Internal\Repository\Interface\DocumentRestrictionLogRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\DocumentSessionRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\ExternalLinkRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\LimitEncounterCounterRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\UserQuotaAssignmentRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\UserQuotaRecalculationRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\UserQuotaRepositoryInterface;
use Bitrix\Disk\Internal\Repository\LimitEncounterCounterPersistentStorageRepository;
use Bitrix\Disk\Internal\Service\Audit\AuditCaptureService;
use Bitrix\Disk\Internal\Service\Audit\AuditCaptureValidator;
use Bitrix\Disk\Internal\Service\Audit\AuditAccessService;
use Bitrix\Disk\Internal\Service\Audit\AuditAvailabilityService;
use Bitrix\Disk\Internal\Service\Audit\AuditIdempotencyKeyBuilder;
use Bitrix\Disk\Internal\Service\Audit\AuditLicenseResolver;
use Bitrix\Disk\Internal\Service\Audit\AuditLicenseResolverInterface;
use Bitrix\Disk\Internal\Service\Audit\AuditOutboxService;
use Bitrix\Disk\Internal\Service\Audit\AuditQueryService;
use Bitrix\Disk\Internal\Service\Audit\AuditRecordService;
use Bitrix\Disk\Internal\Service\Audit\AuditRetentionService;
use Bitrix\Disk\Internal\Service\Audit\AuditSettingsEventFactory;
use Bitrix\Disk\Internal\Service\Audit\AuditSettingsService;
use Bitrix\Disk\Internal\Service\Audit\AuditSettingsUpdateLock;
use Bitrix\Disk\Internal\Service\CustomServerConfig;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueRateLimiter;
use Bitrix\Disk\Internal\Service\Restrictions\EffectivePolicyResolver;
use Bitrix\Disk\Internal\Service\Restrictions\ExtensionListNormalizer;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyRuleCreationLock;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyRuleIndex;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyRuleService;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyService;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPolicyStorage;
use Bitrix\Disk\Internal\Service\Restrictions\FormatPresetMap;
use Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionService;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory;
use Bitrix\Disk\Internal\Service\DocumentHeader\RenameService;
use Bitrix\Disk\Internal\Service\DocumentPresence\InitialRosterService;
use Bitrix\Disk\Internal\Service\DocumentPresence\PresenceAccessService;
use Bitrix\Disk\Internal\Service\DocumentPresence\PresenceService;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkOpenPolicy;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkScenarioRegistry;
use Bitrix\Disk\Internal\Service\ExternalLink\MailAttachmentExternalLinkService;
use Bitrix\Disk\Internal\Service\Folder\MailAttachmentsFolderPredicate;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\DocumentChannelReporter;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\DocumentSaveService;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\DownloadedFileVerifier;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\EditedDocumentDownloaderInterface;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\InterruptedSessionNotifier;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\InterruptedSessionService;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\KeptDocumentRecovery;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SaveTimingConfig;
use Bitrix\Disk\Internal\Service\OnlyOffice\Save\SavingMarker;
use Bitrix\Disk\Internal\Service\Sync\Relation\RelationProviderRegistry;
use Bitrix\Disk\Internal\Service\Sync\Snapshot\SnapshotService;
use Bitrix\Disk\Internal\Service\VersionMatcher\DottedMatcher;
use Bitrix\Disk\Internal\Service\VersionMatcher\Matcher;
use Bitrix\Disk\Internals\DeletedLogManager;
use Bitrix\Disk\Internals\DeletionNotifyManager;
use Bitrix\Disk\Integration\AiAssistant\ToolSet\DiskToolSet;
use Bitrix\Disk\Internals\Runtime\StorageRuntimeCache;
use Bitrix\Disk\Public\Provider\RestrictionsAvailabilityProvider;
use Bitrix\Disk\QuickAccess;
use Bitrix\Disk\QuickAccess\Config\ConfigInterface;
use Bitrix\Disk\QuickAccess\FileInfo\DiskProvider;
use Bitrix\Disk\QuickAccess\FileInfo\MainProvider;
use Bitrix\Disk\QuickAccess\FileInfo\ProviderFactory;
use Bitrix\Disk\Public\Command\ExternalLink\CreateMailAttachmentLink\CreateMailAttachmentLinkCommandHandler;
use Bitrix\Disk\RecentlyUsedManager;
use Bitrix\Disk\Rest\RestManager;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Search\IndexManager;
use Bitrix\Disk\Uf\UserFieldManager;
use Bitrix\Disk\UrlManager;
use Bitrix\Disk\TrackedObjectManager;
use Bitrix\Main\Application;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\NullLogger;

$supportedOnlyOfficeVersions = [
	'9.2.*.*',
];

$supportedR7Versions = [
	'2025.4.*.*',
	'2025.3.*.*',
];

$supportedOnlyOfficeAndR7Versions = array_merge($supportedOnlyOfficeVersions, $supportedR7Versions);

return [
	'aiassistant.marta' => [
		'value' => [
			'toolSets' => [
				DiskToolSet::class,
			],
		],
		'readonly' => true,
	],
	'controllers' => [
		'value' => [
			'namespaces' => [
				'\\Bitrix\\Disk\\Controller' => 'api',
			],
			'defaultNamespace' => '\\Bitrix\\Disk\\Controller',
		],
		'readonly' => true,
	],
	'feature-flags' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Disk\\Public\\Features',
		],
		'readonly' => true,
	],
	'rest' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Disk\\Infrastructure\\Rest\\V3\\Controller',
			// The automatic generator writes a single "200" per method, so a refusal never reaches the
			// published document. Every method of the sync contour brings a provider that describes its
			// own envelope and the refusals it answers with.
			'documentation' => [
				'methods' => [
					'disk.sync.capabilities.get' => CapabilitiesGetMethodProvider::class,
					'disk.sync.session.create' => SessionCreateMethodProvider::class,
					'disk.sync.snapshot.list' => SnapshotListMethodProvider::class,
					'disk.sync.changes.list' => ChangesListMethodProvider::class,
					'disk.sync.state.check' => StateCheckMethodProvider::class,
					'disk.sync.content.get' => ContentGetMethodProvider::class,
					'disk.sync.index.get' => IndexGetMethodProvider::class,
				],
			],
		],
	],
	'services' => [
		'value' => [
			'disk.onlyofficeConfiguration' => [
				'className' => OnlyOffice\Configuration::class,
			],
			'disk.vibeofficeConfiguration' => [
				'className' => Vibeoffice\Configuration::class,
			],
			SaveTimingConfig::class => [
				'constructor' => static function() {
					return SaveTimingConfig::fromModuleOptions();
				},
			],
			// The seam a test replaces to save a document without an editor server behind the webhook.
			EditedDocumentDownloaderInterface::class => [
				'constructor' => static function() {
					return new OnlyOffice\FileDownloader(
						timingConfig: ServiceLocator::getInstance()->get(SaveTimingConfig::class),
					);
				},
			],
			DownloadedFileVerifier::class => [
				'className' => DownloadedFileVerifier::class,
			],
			// The mark of a save in progress: the storage behind it is the seam a test replaces.
			SavingMarker::class => [
				'constructor' => static function() {
					return new SavingMarker(
						timingConfig: ServiceLocator::getInstance()->get(SaveTimingConfig::class),
					);
				},
			],
			DocumentSaveService::class => [
				'constructor' => static function() {
					$locator = ServiceLocator::getInstance();

					return new DocumentSaveService(
						$locator->get(EditedDocumentDownloaderInterface::class),
						$locator->get(DownloadedFileVerifier::class),
					);
				},
			],
			// The log of the save path; everyone who writes into it takes it from here, and a test
			// replaces this very instance.
			//
			// A portal which configures no channel of this id must not fall back to the default logger of
			// the core: that one is the common file log of the portal, written under an exclusive lock and
			// never rotated. The refusals of this path arrive in bursts - every open editor reports one
			// when the editor server goes away - so they go to the event log instead, where the portal
			// already keeps what it has to answer for afterwards.
			//
			// A literal, not DocumentSaveService::LOGGER_SERVICE_ID: the core reads this file on pages
			// without the module, where a class constant cannot be resolved.
			'disk.onlyoffice.saveLogger' => [
				'constructor' => static function() {
					return (new LoggerFactory(false))->createById(
						DocumentSaveService::LOGGER_ID,
						isCheckEnabledFromRegistry: false,
						returnDefaultLoggerIfNotExists: false,
					) ?? new \Bitrix\Main\Diag\EventLogger(
						'disk',
						'DISK_ONLYOFFICE_SAVE_FAILED',
						static fn (array $context): array => [
							'ITEM_ID' => (string)($context['objectId'] ?? ''),
						],
					);
				},
			],
			DocumentChannelReporter::class => [
				'className' => DocumentChannelReporter::class,
			],
			// The notifier is the seam a test replaces to read the recipients of an interrupted session.
			// The client of the command service behind it is the seam a test replaces.
			KeptDocumentRecovery::class => [
				'className' => KeptDocumentRecovery::class,
			],
			InterruptedSessionNotifier::class => [
				'className' => InterruptedSessionNotifier::class,
			],
			InterruptedSessionService::class => [
				'constructor' => static function() {
					$locator = ServiceLocator::getInstance();

					return new InterruptedSessionService(
						$locator->get(SavingMarker::class),
						$locator->get(InterruptedSessionNotifier::class),
						$locator->get(DocumentChannelReporter::class),
					);
				},
			],
			'disk.urlManager' => [
				'className' => UrlManager::class,
			],
			HeaderCompositionService::class => [
				'constructor' => static function() {
					$locator = ServiceLocator::getInstance();

					// The boost service is left unasked for: the header resolves it only when it draws Boost.
					return new HeaderCompositionService(
						$locator->get('disk.urlManager'),
					);
				},
			],
			// The access check of the unified link is left unasked: the builder resolves it only for an
			// opening authorized by a link.
			OpeningContextFactory::class => [
				'className' => OpeningContextFactory::class,
			],
			RenameService::class => [
				'className' => RenameService::class,
			],
			// One access service for every consumer of the request: it caches the users and the avatars
			// it has already queried, and the roster asks about the same ones twice.
			PresenceAccessService::class => [
				'className' => PresenceAccessService::class,
			],
			PresenceService::class => [
				'constructor' => static function() {
					return new PresenceService(
						presenceAccessService: ServiceLocator::getInstance()->get(PresenceAccessService::class),
					);
				},
			],
			InitialRosterService::class => [
				'constructor' => static function() {
					$locator = ServiceLocator::getInstance();

					return new InitialRosterService(
						$locator->get(PresenceService::class),
						$locator->get(PresenceAccessService::class),
					);
				},
			],
			// The page of the initial fetch. Declared here rather than built by its caller because the
			// confirmation of the source of access is a seam of the fetch: an implementation of it is
			// wired by replacing this declaration, without touching the method that serves the page.
			SnapshotService::class => [
				'className' => SnapshotService::class,
			],
			// One registry per request: it asks the owner modules for their providers once, and holding
			// the answer any longer would outlive the installation or the switching off of a module.
			RelationProviderRegistry::class => [
				'className' => RelationProviderRegistry::class,
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
			// The very instance 'disk.rightsManager' keeps, under the name of the class: RightsManager
			// orders the background jobs it makes by a counter of its own, so a second instance of it
			// would number its jobs over the ones the first has already made. Autowiring must therefore
			// hand out the one instance the module works with.
			RightsManager::class => [
				'constructor' => static function () {
					return ServiceLocator::getInstance()->get('disk.rightsManager');
				},
			],
			'disk.ufManager' => [
				'className' => UserFieldManager::class,
			],
			'disk.indexManager' => [
				'className' => IndexManager::class,
			],
			'disk.boardIndexingLogger' => [
				'constructor' => static function () {
					return (new LoggerFactory(false))->createById(
						'disk.boardIndexing',
						isCheckEnabledFromRegistry: false,
						returnDefaultLoggerIfNotExists: false,
					) ?? new \Bitrix\Main\Diag\EventLogger(
						'disk',
						'DISK_BOARD_INDEXING_FAILED',
						static fn (array $context): array => ['ITEM_ID' => (string)$context['objectId']],
					);
				},
			],
			'disk.searchIndexingLogger' => [
				'constructor' => static function () {
					return (new LoggerFactory(false))->createById(
						'disk.searchIndexing',
						isCheckEnabledFromRegistry: false,
						returnDefaultLoggerIfNotExists: false,
					) ?? new \Bitrix\Main\Diag\EventLogger(
						'disk',
						'DISK_SEARCH_INDEXING_SKIPPED',
						static fn (array $context): array => ['ITEM_ID' => (string)$context['objectId']],
					);
				},
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
			UserQuotaRepositoryInterface::class => [
				'className' => BitrixOrmUserQuotaRepository::class,
			],
			UserQuotaRecalculationRepositoryInterface::class => [
				'className' => BitrixOrmUserQuotaRepository::class,
			],
			UserQuotaAssignmentRepositoryInterface::class => [
				'className' => BitrixOrmUserQuotaRepository::class,
			],
			// A string service id is built without autowiring, so the provider is resolved by its
			// own class key: the id stays a stable handle while dependencies stay in the constructor.
			'disk.restrictionsAvailabilityProvider' => [
				'constructor' => static function () {
					return ServiceLocator::getInstance()->get(RestrictionsAvailabilityProvider::class);
				},
			],
			'disk.formatPolicyService' => [
				'constructor' => static function () {
					return ServiceLocator::getInstance()->get(FormatPolicyService::class);
				},
			],
			// One index per hit: it keeps the rules it has read for the rest of the request.
			FormatPolicyRuleIndex::class => [
				'className' => FormatPolicyRuleIndex::class,
				'constructorParams' => static function () {
					$locator = ServiceLocator::getInstance();

					return [
						$locator->get(ManagedCache::class),
						$locator->get(FormatPolicyRuleRepository::class),
					];
				},
			],
			// One resolver per hit: it keeps the policy of every user it was asked about.
			EffectivePolicyResolver::class => [
				'className' => EffectivePolicyResolver::class,
				'constructorParams' => static function () {
					$locator = ServiceLocator::getInstance();

					return [
						$locator->get(FormatPolicyRuleIndex::class),
						$locator->get(FormatPolicyStorage::class),
						$locator->get(FormatPolicyRuleRepository::class),
					];
				},
			],
			// Shares the index of the hit, so a saved or removed rule is seen by the rest of the request.
			FormatPolicyRuleService::class => [
				'className' => FormatPolicyRuleService::class,
				'constructorParams' => static function () {
					$locator = ServiceLocator::getInstance();

					return [
						$locator->get(FormatPolicyRuleRepository::class),
						$locator->get(FormatPolicyRuleIndex::class),
						$locator->get(ExtensionListNormalizer::class),
						$locator->get(FormatPresetMap::class),
						$locator->get(EffectivePolicyResolver::class),
						$locator->get(FormatPolicyRuleCreationLock::class),
						$locator->get(TeamDirectory::class),
					];
				},
			],
			// One directory per hit: it remembers whether humanresources is installed and the default structure.
			TeamDirectory::class => [
				'className' => TeamDirectory::class,
			],
			// The same directory of the hit, by the abstraction the format rules depend on.
			TeamDirectoryInterface::class => [
				'constructor' => static function () {
					return ServiceLocator::getInstance()->get(TeamDirectory::class);
				},
			],
			FormatPolicyRuleCreationLock::class => [
				'className' => FormatPolicyRuleCreationLock::class,
				'constructorParams' => static function () {
					return [Application::getConnection()];
				},
			],
			AuditEventRepository::class => [
				'className' => AuditEventRepository::class,
			],
			AuditEventMapper::class => [
				'className' => AuditEventMapper::class,
			],
			AuditIdempotencyKeyBuilder::class => [
				'className' => AuditIdempotencyKeyBuilder::class,
			],
			AuditRecordService::class => [
				'className' => AuditRecordService::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get(AuditEventRepository::class),
						$locator->get(AuditEventMapper::class),
						$locator->get(AuditIdempotencyKeyBuilder::class),
					];
				},
			],
			AuditRetentionService::class => [
				'className' => AuditRetentionService::class,
			],
			AuditOutboxService::class => [
				'className' => AuditOutboxService::class,
			],
			AuditCaptureValidator::class => [
				'className' => AuditCaptureValidator::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get(AuditIdempotencyKeyBuilder::class),
					];
				},
			],
			AuditCaptureService::class => [
				'className' => AuditCaptureService::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get(AuditOutboxService::class),
						$locator->get(AuditCaptureValidator::class),
					];
				},
			],
			AuditSettingsService::class => [
				'className' => AuditSettingsService::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get(AuditSettingsUpdateLock::class),
					];
				},
			],
			AuditLicenseResolverInterface::class => [
				'className' => AuditLicenseResolver::class,
			],
			AuditLicenseResolver::class => [
				'className' => AuditLicenseResolver::class,
			],
			AuditAvailabilityService::class => [
				'className' => AuditAvailabilityService::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get(AuditSettingsService::class),
						$locator->get(AuditLicenseResolverInterface::class),
					];
				},
			],
			AuditAccessService::class => [
				'className' => AuditAccessService::class,
				'constructorParams' => static function() {
					return [
						\Bitrix\Main\DI\ServiceLocator::getInstance()->get(AuditAvailabilityService::class),
					];
				},
			],
			AuditQueryService::class => [
				'className' => AuditQueryService::class,
			],
			AuditSettingsEventFactory::class => [
				'className' => AuditSettingsEventFactory::class,
			],
			AuditSettingsUpdateLock::class => [
				'className' => AuditSettingsUpdateLock::class,
			],
			AuditReceiver::class => [
				'className' => AuditReceiver::class,
				'constructorParams' => static function() {
					return [
						\Bitrix\Main\DI\ServiceLocator::getInstance()->get(AuditRecordService::class),
					];
				},
			],
			'disk.audit' => [
				'constructor' => static function() {
					return (new LoggerFactory())->createById('disk.audit') ?? new NullLogger();
				},
			],
			ExternalLinkOpenPolicy::class => [
				'constructor' => static function() {
					return ExternalLinkOpenPolicy::fromPortalConfiguration();
				},
			],
			MailAttachmentExternalLinkService::class => [
				'constructor' => static function() {
					$serviceLocator = ServiceLocator::getInstance();

					return new MailAttachmentExternalLinkService(
						$serviceLocator->get(MailAttachmentsFolderPredicate::class),
						$serviceLocator->get(ExternalLinkRepositoryInterface::class),
						$serviceLocator->get(ExternalLinkScenarioRegistry::class),
						(bool)Bitrix24Manager::isFeatureEnabled(MailAttachmentExternalLinkService::FEATURE),
					);
				},
			],
			CreateMailAttachmentLinkCommandHandler::class => [
				'constructor' => static function() {
					return new CreateMailAttachmentLinkCommandHandler(
						ServiceLocator::getInstance()->get(MailAttachmentExternalLinkService::class),
						(int)CurrentUser::get()->getId(),
					);
				},
				// A new handler on every resolve: it carries the current user, and a process can change them.
				'singleton' => false,
			],
			DualMode\BoardApiServiceFactory::class => [
				'className' => DualMode\DefaultBoardApiServiceFactory::class,
			],
			DockedUploadQueueRepositoryInterface::class => [
				'className' => DockedUploadQueueRepository::class,
			],
			'disk.dockedUpload.rateLimiter' => [
				'constructor' => static function () {
					return new QueueRateLimiter(
						ServiceLocator::getInstance()->get(PersistentStorageInterface::class),
					);
				},
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
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'disk-special-access',
					'provider' => [
						'moduleId' => 'disk',
						'className' => SpecialAccessProvider::class,
					],
				],
				[
					'entityId' => 'disk-file',
					'provider' => [
						'moduleId' => 'disk',
						'className' => FileProvider::class,
					],
				],
			],
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
			'brokers' => [
				'disk_audit' => [
					'type' => 'db',
					'params' => [
						'module' => 'disk',
						'table' => AuditMessengerMessageTable::class,
					],
				],
			],
			'queues' => [
				'download_board' => [
					'handler' => \Bitrix\Disk\Document\Flipchart\Messenger\DownloadBoardReceiver::class,
				],
				// Must match KeptDocumentSaveMessage::QUEUE; see the literal of the save logger above.
				'onlyoffice_kept_document_save' => [
					'handler' => \Bitrix\Disk\Internal\Service\OnlyOffice\Save\Messenger\KeptDocumentSaveReceiver::class,
				],
				'index_board' => [
					'handler' => \Bitrix\Disk\Search\Messenger\IndexBoardReceiver::class,
					'limit' => 1,
					'total_processing_limit' => 1,
					'retry_strategy' => [
						'max_retries' => 3,
						'delay' => 1,
						'multiplier' => 2,
					],
				],
				'disk_audit' => [
					'broker' => 'disk_audit',
					'handler' => AuditReceiver::class,
					'retry_strategy' => [
						'max_retries' => 3,
						'delay' => 1,
						'multiplier' => 2,
					],
				],
			],
		],
		'readonly' => true,
	],
];
