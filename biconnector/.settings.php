<?php

use Bitrix\BIConnector\Cli\Key\AuditCommand;
use Bitrix\BIConnector\Cli\Key\RevokeCommand;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\RateLimit\BiToolsRateLimiter;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet\BiChartsToolSet;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet\BiDashboardsToolSet;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet\BiDatasetsToolSet;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet\BiTablesToolSet;
use Bitrix\BIConnector\Integration\UI\EntitySelector\ExternalConnectionProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\ExternalTableProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\SupersetDashboardProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\SupersetDashboardTagProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\UnlinkedSupersetDashboardProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\SupersetGroupProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\TableGroupProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\DigestPeriodProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\SupersetScopeProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\UsageStatChartProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\UsageStatDashboardProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\UsageStatDatasetProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\UsageStatSourceProvider;
use Bitrix\Biconnector\Internal\Integration\Im\DashboardDiscussionChatAccessSyncService;
use Bitrix\Biconnector\Internal\Integration\Im\DashboardDiscussionChatService;
use Bitrix\Biconnector\Public\Provider\DashboardDetailInfoProvider;
use Bitrix\Biconnector\Public\Services\AhaMoment\AhaMomentSpotlightResolver;
use Bitrix\Biconnector\Internal\Services\DashboardInfo\FileCleanupService;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardShareMapper;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareBanRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Internal\Services\Share\AuthorShareStateService;
use Bitrix\BIConnector\Internal\Services\Share\SharePasswordService;
use Bitrix\BIConnector\Public\Provider\AgentOperationsProvider;
use Bitrix\BIConnector\Public\Provider\ShareProvider;
use Bitrix\BIConnector\Public\Provider\DigestRecipientAccessProvider;
use Bitrix\BIConnector\Public\Provider\ScopeAccessibleValueProvider;
use Bitrix\BIConnector\Internal\Digest\Logger\DigestRunLogger;
use Bitrix\BIConnector\Internal\Digest\Provider\BiAvailabilityProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\BiInstanceStatusProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\BizprocLiveWorkflowInstanceProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\DashboardViewRightChecker;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestDashboardProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\DigestIntegratorProvider;
use Bitrix\BIConnector\Internal\Digest\Provider\ScopeValueAccessChecker;
use Bitrix\BIConnector\Internal\Digest\Service\InstanceWarmupService;
use Bitrix\BIConnector\Internal\Digest\Service\OverviewWarmupService;
use Bitrix\BIConnector\Internal\Digest\Service\DashboardInstallService;
use Bitrix\BIConnector\Internal\Digest\Service\RecipientReadinessService;
use Bitrix\BIConnector\Internal\Digest\Service\RunReadinessService;
use Bitrix\BIConnector\Internal\Digest\Onboarding\DigestSetupOnboarding;
use Bitrix\BIConnector\Internal\Digest\Service\DigestRecipientAccessCheckService;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupAvailabilityService;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupPreparationService;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

// Parsed by Configuration::load() before the biconnector module is included, so the gate
// must read the option directly: biconnector classes (Feature) aren't autoloadable here yet,
// and referencing one breaks extension loading portal-wide. Mirrors
// Feature::isBitrixGptBiConstructorEnabled().
$biToolSets = Option::get('biconnector', 'bitrixgpt_bi_constructor', 'N') === 'Y'
	? [
		BiDashboardsToolSet::class,
		BiTablesToolSet::class,
		BiDatasetsToolSet::class,
		BiChartsToolSet::class,
	]
	: [];

return [
	'console' => [
		'value' => [
			'commands' => [
				AuditCommand::class,
				RevokeCommand::class,
			],
		],
		'readonly' => true,
	],
	'feature-flags' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\BIConnector\\Public\\Features',
		],
		'readonly' => true,
	],
	'rest' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\BIConnector\\Infrastructure\\Rest\\Controller',
			'documentation' => [
				'methods' => [
					'biconnector.builder.dashboard.list' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DashboardListMethodProvider',
					'biconnector.builder.dashboard.get' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DashboardGetMethodProvider',
					'biconnector.builder.dashboard.add' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DashboardAddMethodProvider',
					'biconnector.builder.dashboard.update' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DashboardUpdateMethodProvider',
					'biconnector.builder.dashboard.content.set' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DashboardContentSetMethodProvider',
					'biconnector.builder.dashboard.delete' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DashboardDeleteMethodProvider',
					'biconnector.builder.dashboard.copy' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DashboardCopyMethodProvider',
					'biconnector.builder.dashboard.filter.values' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DashboardFilterValuesMethodProvider',
					'biconnector.builder.dashboard.parameters.search' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DashboardParametersSearchMethodProvider',
					'biconnector.builder.chart.list' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'ChartListMethodProvider',
					'biconnector.builder.chart.get' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'ChartGetMethodProvider',
					'biconnector.builder.chart.add' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'ChartAddMethodProvider',
					'biconnector.builder.chart.update' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'ChartUpdateMethodProvider',
					'biconnector.builder.chart.config.set' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'ChartConfigSetMethodProvider',
					'biconnector.builder.chart.delete' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'ChartDeleteMethodProvider',
					'biconnector.builder.chart.data.get' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'ChartDataGetMethodProvider',
					'biconnector.builder.dataset.list' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DatasetListMethodProvider',
					'biconnector.builder.dataset.get' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DatasetGetMethodProvider',
					'biconnector.builder.dataset.add' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DatasetAddMethodProvider',
					'biconnector.builder.dataset.addfromtable' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DatasetAddFromTableMethodProvider',
					'biconnector.builder.dataset.update' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DatasetUpdateMethodProvider',
					'biconnector.builder.dataset.delete' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'DatasetDeleteMethodProvider',
					'biconnector.builder.table.list' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'TableListMethodProvider',
					'biconnector.builder.table.schema.get' => 'Bitrix\\BIConnector\\'
						. 'Infrastructure\\Rest\\Documentation\\Builder\\Method\\'
							. 'TableSchemaGetMethodProvider',
				],
			],
		],
		'readonly' => true,
	],
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\BIConnector\\Controller',
			'restIntegration' => [
				'enabled' => true,
			],
		],
		'readonly' => true,
	],
	'ui.uploader' => [
		'value' => [
			'allowUseControllers' => true,
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			'biconnector.service.dashboardInfo.fileCleanup' => [
				'className' => FileCleanupService::class,
			],
			'biconnector.service.dashboardDiscussionChat' => [
				'className' => DashboardDiscussionChatService::class,
			],
			'biconnector.service.dashboardDiscussionChatAccessSync' => [
				'className' => DashboardDiscussionChatAccessSyncService::class,
			],
			'biconnector.service.ahaMomentSpotlightResolver' => [
				'className' => AhaMomentSpotlightResolver::class,
			],
			'biconnector.provider.dashboardDetailInfo' => [
				'className' => DashboardDetailInfoProvider::class,
			],
			'biconnector.repository.share.mapper' => [
				'className' => SupersetDashboardShareMapper::class,
			],
			'biconnector.repository.share' => [
				'className' => SupersetDashboardShareRepository::class,
				'constructorParams' => static fn() => [
					'mapper' => \Bitrix\Main\DI\ServiceLocator::getInstance()->get('biconnector.repository.share.mapper'),
				],
			],
			'biconnector.repository.shareBan' => [
				'className' => SupersetDashboardShareBanRepository::class,
			],
			'biconnector.service.sharePassword' => [
				'className' => SharePasswordService::class,
				'constructorParams' => static fn() => [
					'repository' => \Bitrix\Main\DI\ServiceLocator::getInstance()->get('biconnector.repository.share'),
				],
			],
			'biconnector.service.authorShareState' => [
				'className' => AuthorShareStateService::class,
				'constructorParams' => static fn() => [
					'repository' => \Bitrix\Main\DI\ServiceLocator::getInstance()->get('biconnector.repository.share'),
					'banRepository' => \Bitrix\Main\DI\ServiceLocator::getInstance()->get('biconnector.repository.shareBan'),
				],
			],
			'biconnector.provider.share' => [
				'className' => ShareProvider::class,
				'constructorParams' => static fn() => [
					'repository' => \Bitrix\Main\DI\ServiceLocator::getInstance()->get('biconnector.repository.share'),
				],
			],
			'biconnector.provider.scopeAccessibleValue' => [
				'className' => ScopeAccessibleValueProvider::class,
			],
			'biconnector.provider.agentOperations' => [
				'className' => AgentOperationsProvider::class,
			],
			'biconnector.service.biToolsRateLimiter' => [
				'className' => BiToolsRateLimiter::class,
			],
			DashboardInstallService::class => [
				'className' => DashboardInstallService::class,
				'constructorParams' => static fn() => [
					'dashboardManager' => MarketDashboardManager::getInstance(),
					'application' => Application::getInstance(),
				],
			],
			// One instance for the readiness check and the warmup: the check reads the report card the
			// warmup of the same run has already received instead of asking Superset again.
			DigestDashboardProvider::class => [
				'className' => DigestDashboardProvider::class,
			],
			'biconnector.service.digestRecipientReadiness' => [
				'className' => RecipientReadinessService::class,
				'constructorParams' => static fn() => [
					'dashboardProvider' => \Bitrix\Main\DI\ServiceLocator::getInstance()
						->get(DigestDashboardProvider::class),
					'rightChecker' => new DashboardViewRightChecker(),
					'instanceStatusProvider' => new BiInstanceStatusProvider(),
					'scopeValueAccessChecker' => new ScopeValueAccessChecker(
						\Bitrix\Main\DI\ServiceLocator::getInstance()->get('biconnector.provider.scopeAccessibleValue'),
					),
					'installService' => \Bitrix\Main\DI\ServiceLocator::getInstance()
						->get(DashboardInstallService::class),
				],
			],
			OverviewWarmupService::class => [
				'className' => OverviewWarmupService::class,
				'constructorParams' => static fn() => [
					'integratorProvider' => new DigestIntegratorProvider(),
					'dashboardProvider' => \Bitrix\Main\DI\ServiceLocator::getInstance()
						->get(DigestDashboardProvider::class),
				],
			],
			DigestRecipientAccessProvider::class => [
				'className' => DigestRecipientAccessProvider::class,
				'constructorParams' => static fn() => [
					'readinessService' => \Bitrix\Main\DI\ServiceLocator::getInstance()
						->get('biconnector.service.digestRecipientReadiness'),
				],
			],
			DigestRecipientAccessCheckService::class => [
				'className' => DigestRecipientAccessCheckService::class,
				'constructorParams' => static fn() => [
					'accessProvider' => \Bitrix\Main\DI\ServiceLocator::getInstance()
						->get(DigestRecipientAccessProvider::class),
				],
			],
			// One instance per hit: the grid, the report screen and the onboarding share its answer about
			// the portal instead of asking it each.
			DigestSetupAvailabilityService::class => [
				'className' => DigestSetupAvailabilityService::class,
			],
			DigestSetupOnboarding::class => [
				'className' => DigestSetupOnboarding::class,
				'constructorParams' => static fn() => [
					'availabilityService' => \Bitrix\Main\DI\ServiceLocator::getInstance()
						->get(DigestSetupAvailabilityService::class),
				],
			],
			DigestSetupPreparationService::class => [
				'className' => DigestSetupPreparationService::class,
				'constructorParams' => static fn() => [
					'availabilityService' => \Bitrix\Main\DI\ServiceLocator::getInstance()
						->get(DigestSetupAvailabilityService::class),
				],
			],
			'biconnector.service.digestRunReadiness' => [
				'className' => RunReadinessService::class,
				'constructorParams' => static function (): array {
					$instanceStatusProvider = new BiInstanceStatusProvider();

					return [
						'availabilityProvider' => new BiAvailabilityProvider(),
						'instanceStatusProvider' => $instanceStatusProvider,
						'liveInstanceProvider' => new BizprocLiveWorkflowInstanceProvider(),
						'warmupService' => new InstanceWarmupService(
							$instanceStatusProvider,
							new DigestIntegratorProvider(),
							\Bitrix\Main\DI\ServiceLocator::getInstance()->get(DigestDashboardProvider::class),
						),
						'runLogger' => new DigestRunLogger(),
					];
				},
			],
		],
		'readonly' => true,
	],
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'biconnector-superset-dashboard',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => SupersetDashboardProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-superset-unlinked-dashboard',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => UnlinkedSupersetDashboardProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-superset-dashboard-tag',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => SupersetDashboardTagProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-superset-scope',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => SupersetScopeProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-digest-period',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => DigestPeriodProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-external-connection',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => ExternalConnectionProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-external-table',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => ExternalTableProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-superset-group',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => SupersetGroupProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-table-group',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => TableGroupProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-usage-stat-dashboard',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => UsageStatDashboardProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-usage-stat-chart',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => UsageStatChartProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-usage-stat-dataset',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => UsageStatDatasetProvider::class,
					],
				],
				[
					'entityId' => 'biconnector-usage-stat-source',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => UsageStatSourceProvider::class,
					],
				],
			],
			'extensions' => ['biconnector.entity-selector'],
		],
		'readonly' => true,
	],
	'aiassistant.marta' => [
		'value' => [
			'toolSets' => $biToolSets,
		],
		'readonly' => true,
	],
];
