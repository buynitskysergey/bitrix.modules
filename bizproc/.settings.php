<?php

use Bitrix\Bizproc\Integration\UI\EntitySelector\AccessTemplateProvider;
use Bitrix\Bizproc\Integration\UI\EntitySelector\AutomationTemplateProvider;
use Bitrix\Bizproc\Integration\UI\EntitySelector\DocumentProvider;
use Bitrix\Bizproc\Integration\UI\EntitySelector\DocumentTypeProvider;
use Bitrix\Bizproc\Integration\UI\EntitySelector\ScriptTemplateProvider;
use Bitrix\Bizproc\Integration\UI\EntitySelector\StartWorkflowTemplateProvider;
use Bitrix\Bizproc\Integration\UI\EntitySelector\StorageProvider;
use Bitrix\Bizproc\Integration\UI\EntitySelector\SystemProvider;
use Bitrix\Bizproc\Integration\UI\EntitySelector\TemplateProvider;
use Bitrix\Bizproc\Internal\Service\Trigger\SectionService;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\ManualStartTemplateAvailabilityService;
use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowStartMessageTable;
use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\WorkflowResumeMessageTable;
use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Receiver\WorkflowStartReceiver;
use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Receiver\WorkflowResumeReceiver;
use Bitrix\Main\Messenger\Internals\Broker\DbBroker;
use Bitrix\Main\DI\ServiceLocator;

$pauseQueues = [
	'resume_workflow_delay_queue' => [
		'broker' => 'workflow_resume_db',
		'handler' => WorkflowResumeReceiver::class,
		'limit' => 5,
		'total_processing_limit' => 5,
	],
	'resume_workflow_robot_delay_queue' => [
		'broker' => 'workflow_resume_db',
		'handler' => WorkflowResumeReceiver::class,
		'limit' => 5,
		'total_processing_limit' => 5,
	],
	'resume_workflow_wait_workday_queue' => [
		'broker' => 'workflow_resume_db',
		'handler' => WorkflowResumeReceiver::class,
		'limit' => 5,
		'total_processing_limit' => 5,
	],
];

return [
	'console' => [
		'value' => [
			'commands' => [
				\Bitrix\Bizproc\Cli\NodesExport::class,
				\Bitrix\Bizproc\Cli\AiAgentGenerate::class,
				\Bitrix\Bizproc\Cli\AiAgentReverse::class,
				\Bitrix\Bizproc\Cli\AiAgentActivities::class,
			],
		],
		'readonly' => true,
	],
	'controllers' => [
		'value' => [
			'namespaces' => [
				'\\Bitrix\\Bizproc\\Controller' => 'api',
				'\\Bitrix\\Bizproc\\Infrastructure\\Controller' => 'v2',
			],
			'defaultNamespace' => '\\Bitrix\\Bizproc\\Controller',
			'restIntegration' => [
				'enabled' => false,
			],
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			\Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityServiceInterface::class => [
				'className' => \Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityService::class,
			],
			\Bitrix\Bizproc\Public\Service\AiAgent\NodeAvailabilityServiceInterface::class => [
				'className' => \Bitrix\Bizproc\Public\Service\AiAgent\NodeAvailabilityService::class,
				'constructorParams' => static function() {
					return [
						ServiceLocator::getInstance()->get(
							\Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityServiceInterface::class
						),
					];
				},
			],
			\Bitrix\Bizproc\Public\Service\ActivityGroup\GroupVisibilityServiceInterface::class => [
				'className' => \Bitrix\Bizproc\Public\Service\ActivityGroup\GroupVisibilityService::class,
				'constructorParams' => static function() {
					$nodeAvailabilityService = ServiceLocator::getInstance()->get(
						\Bitrix\Bizproc\Public\Service\AiAgent\NodeAvailabilityServiceInterface::class
					);

					return [
						[
							// Groups hidden when AI agent nodes are unavailable (region/module gated).
							// Add more HideableGroupRuleInterface instances here to hide other groups.
							new \Bitrix\Bizproc\Public\Service\ActivityGroup\NodeAvailabilityGroupRule(
								\Bitrix\Bizproc\Activity\Enum\ActivityGroup::AI,
								$nodeAvailabilityService,
							),
							new \Bitrix\Bizproc\Public\Service\ActivityGroup\NodeAvailabilityGroupRule(
								\Bitrix\Bizproc\Activity\Enum\ActivityGroup::MCP,
								$nodeAvailabilityService,
							),
							// Still under internal rollout: hidden until the option is set to 'Y'.
							new \Bitrix\Bizproc\Public\Service\ActivityGroup\OptionGroupRule(
								\Bitrix\Bizproc\Activity\Enum\ActivityGroup::WORKFLOW_STATE,
								\Bitrix\Bizproc\Internal\Config\WorkflowStateGroupFeature::MODULE_ID,
								\Bitrix\Bizproc\Internal\Config\WorkflowStateGroupFeature::OPTION_NAME,
							),
						],
					];
				},
			],
			'bizproc.service.schedulerService' => [
				'className' => '\\CBPSchedulerService',
			],
			'bizproc.service.stateService' => [
				'className' => '\\CBPStateService',
			],
			/** @see autoload.php */
			//'bizproc.service.trackingService' => [
			//	'className' => '\\CBPTrackingService',
			//],
			'bizproc.service.taskService' => [
				'className' => '\\CBPTaskService',
			],
			'bizproc.service.historyService' => [
				'className' => '\\CBPHistoryService',
			],
			'bizproc.service.documentService' => [
				'className' => '\\CBPDocumentService',
			],
			'bizproc.service.analyticsService' => [
				'className' => '\\Bitrix\\Bizproc\\Service\\Analytics',
			],
			'bizproc.service.userService' => [
				'className' => '\\Bitrix\\Bizproc\\Service\\User',
			],
			'bizproc.service.aiDescriptionService' => [
				'className' => '\\Bitrix\\Bizproc\\Service\\AiDescription',
			],
			'bizproc.debugger.service.trackingService' => [
				'className' => '\\Bitrix\\Bizproc\\Debugger\\Services\\TrackingService',
			],
			'bizproc.debugger.service.analyticsService' => [
				'className' => '\\Bitrix\\Bizproc\\Debugger\\Services\\AnalyticsService',
			],
			'bizproc.workflow.state.repository.mapper' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Mapper\\WorkflowStateMapper',
			],
			'bizproc.workflow.state.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\WorkflowStateRepository\\WorkflowStateRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getWorkflowStatRepositoryMapper(),
					];
				},
			],
			'bizproc.task.user.repository.mapper' => [
				'className' => '\Bitrix\Bizproc\Internal\Repository\Mapper\TaskUserMapper',
			],
			'bizproc.task.repository.mapper' => [
				'className' => '\Bitrix\Bizproc\Internal\Repository\Mapper\TaskMapper',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getTaskUserRepositoryMapper(),
					];
				},
			],
			'bizproc.task.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\TaskRepository\\TaskRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getTaskRepositoryMapper(),
					];
				},
			],
			'bizproc.task.archive.repository.mapper' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Mapper\\TaskArchiveMapper',
			],
			'bizproc.task.archive.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\TaskArchiveRepository\\TaskArchiveRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getTaskArchiveRepositoryMapper(),
					];
				},
			],
			'bizproc.task.archive.tasks.repository.mapper' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Mapper\\TaskArchiveTasksMapper',
			],
			'bizproc.task.archive.tasks.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\TaskArchiveRepository\\TaskArchiveTasksRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getTaskArchiveTasksRepositoryMapper(),
					];
				},
			],
			'bizproc.archive.task.service' => [
				'className' => \Bitrix\Bizproc\Public\Service\Task\ArchiveTaskService::class,
				'constructorParams' => static function() {
					return [
						'archiveRepository' => \Bitrix\Bizproc\Internal\Container::getTaskArchiveRepository(),
						'archiveTasksRepository' => \Bitrix\Bizproc\Internal\Container::getTaskArchiveTasksRepository(),
						'taskRepository' => \Bitrix\Bizproc\Internal\Container::getTaskRepository(),
					];
				},
			],
			'bizproc.runtime.activitysearcher.searcher' => [
				'className' => \Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher::class,
				// Explicit DI: no Service Locator inside Searcher.
				'constructorParams' => static function() {
					return [
						\Bitrix\Main\DI\ServiceLocator::getInstance()
							->get('bizproc.service.activity.unifiedPanelDescriptor'),
					];
				},
			],
			'bizproc.service.activity.unifiedPanelDescriptor' => [
				'className' => \Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider::class,
			],
			'bizproc.service.activity.targetDocumentResolverRegistry' => [
				'className' => \Bitrix\Bizproc\Public\Activity\Registry\TargetDocumentResolverRegistry::class,
			],
			'bizproc.service.activity.targetDocumentAccessGuardRegistry' => [
				'className' => \Bitrix\Bizproc\Public\Activity\Registry\TargetDocumentAccessGuardRegistry::class,
			],
			'bizproc.service.activity.filterResultPropertyResolverRegistry' => [
				'className' => \Bitrix\Bizproc\Public\Activity\Registry\FilterResultPropertyResolverRegistry::class,
			],
			'bizproc.service.dataView.providerRegistry' => [
				'className' => \Bitrix\Bizproc\Public\DataView\Registry\DataSourceProviderRegistry::class,
			],
			'bizproc.service.dataView.storageProvider' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\Provider\StorageDataSourceProvider::class,
			],
			'bizproc.service.dataView.variablesProvider' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\Provider\VariablesDataSourceProvider::class,
			],
			'bizproc.service.dataView.nativeProvider' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\Provider\CompositeDataSourceProvider::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get('bizproc.service.dataView.storageProvider'),
						$locator->get('bizproc.service.dataView.variablesProvider'),
					];
				},
			],
			'bizproc.service.dataView.valuePresenter' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\ValuePresenter::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get('bizproc.service.dataView.providerRegistry'),
					];
				},
			],
			'bizproc.service.dataView.periodResolver' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\PeriodResolver::class,
			],
			'bizproc.service.dataView.limitsService' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\DataViewLimitsService::class,
			],
			'bizproc.service.dataView.combineEngine' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\CombineEngine::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get('bizproc.service.dataView.providerRegistry'),
						$locator->get('bizproc.service.dataView.periodResolver'),
						$locator->get('bizproc.service.dataView.limitsService'),
						\Bitrix\Bizproc\Internal\Container::getDataViewRepository(),
						null,
						\Bitrix\Bizproc\Internal\Service\DataView\CombineEngine::DEFAULT_DATA_BYTE_LIMIT,
						$locator->get('bizproc.service.dataView.valuePresenter'),
					];
				},
			],
			'bizproc.service.dataView.materializeService' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\MaterializeService::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get('bizproc.service.dataView.combineEngine'),
						\Bitrix\Bizproc\Internal\Container::getDataViewRepository(),
						\Bitrix\Bizproc\Internal\Container::getStorageItemRepository(),
						\Bitrix\Bizproc\Internal\Container::getStorageLimitsService(),
					];
				},
			],
			'bizproc.service.dataView.columnResolver' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\ColumnResolver::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get('bizproc.service.dataView.providerRegistry'),
						\Bitrix\Bizproc\Internal\Container::getStorageLimitsService(),
						\Bitrix\Bizproc\Internal\Container::getStorageFieldRepository(),
						$locator->get('bizproc.service.dataView.periodResolver'),
					];
				},
			],
			'bizproc.service.dataView.validator' => [
				'className' => \Bitrix\Bizproc\Internal\Service\DataView\DataViewValidator::class,
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get('bizproc.service.dataView.columnResolver'),
						\Bitrix\Bizproc\Internal\Container::getDataViewRepository(),
					];
				},
			],
			'bizproc.service.activity.relationFieldResolverRegistry' => [
				'className' => \Bitrix\Bizproc\Public\Activity\Registry\RelationFieldResolverRegistry::class,
			],
			'bizproc.service.eval' => [
				'className' => \Bitrix\Bizproc\Internal\Service\EvalService::class,
			],
			'bizproc.container' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Container',
			],
			'bizproc.storage.type.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\StorageTypeRepository\\StorageTypeRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getStorageTypeRepositoryMapper(),
					];
				},
			],
			'bizproc.storage.type.repository.mapper' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Mapper\\StorageTypeMapper',
			],
			'bizproc.access.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Access\\AccessRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getAccessRoleMapper(),
						\Bitrix\Bizproc\Internal\Container::getAccessPermissionMapper(),
					];
				},
			],
			'bizproc.access.repository.mapper.role' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Mapper\\AccessRoleMapper',
			],
			'bizproc.access.repository.mapper.permission' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Mapper\\AccessPermissionMapper',
			],
			'bizproc.storage.item.repository.mapper' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Mapper\\StorageItemMapper',
			],
			'bizproc.storage.field.repository.mapper' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Mapper\\StorageFieldMapper',
			],
			'bizproc.storage.item.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\StorageItemRepository\\StorageItemRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getStorageItemRepositoryMapper(),
						\Bitrix\Bizproc\Internal\Container::getStorageFieldValueRepository(),
						\Bitrix\Bizproc\Internal\Container::getStorageFieldValidatorService(),
					];
				},
			],
			'bizproc.storage.field.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\StorageFieldRepository\\StorageFieldRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getStorageFieldRepositoryMapper(),
					];
				},
			],
			'bizproc.storage.data_view.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\DataViewRepository\\DataViewRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getDataViewRepositoryMapper(),
					];
				},
			],
			'bizproc.storage.data_view.repository.mapper' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\Mapper\\DataViewMapper',
			],
			'bizproc.service.storage.limits' => [
				'className' => \Bitrix\Bizproc\Internal\Service\Storage\StorageLimitsService::class,
				// Explicit DI — the bitrix24 license singleton is touched only here, in the composition root.
				'constructorParams' => static function() {
					$limitResolver = static function(): ?\Bitrix\Bizproc\Internal\Service\Storage\DiskSpaceLimit {
						if (!\Bitrix\Main\Loader::includeModule('bitrix24'))
						{
							return null;
						}

						$limiter = \Bitrix\Bitrix24\LicenseScanner\Manager::getInstance()->getDiskSpaceLimiter();
						$edition = (string)(\Bitrix\Bitrix24\License::getCurrent()->getCode() ?? '');
						if ($limiter === null || $edition === '')
						{
							return null;
						}

						return new \Bitrix\Bizproc\Internal\Service\Storage\DiskSpaceLimit(
							(int)$limiter->getTargetValue($edition),
							(int)$limiter->getCurrentValue(),
						);
					};

					return [
						new \Bitrix\Bizproc\Internal\Service\Storage\LicenseRemainingDiskSpaceProvider($limitResolver),
					];
				},
			],
			'bizproc.workflow.template.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\WorkflowTemplate\\WorkflowTemplateRepository',
			],
			'bizproc.service.workflowTemplate.manualStartAvailability' => [
				'className' => ManualStartTemplateAvailabilityService::class,
			],
			'bizproc.service.activity.complex' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Service\\Activity\\ComplexActivityService',
				// Explicit DI — no Service Locator inside ComplexActivityService.
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get('bizproc.runtime.activitysearcher.searcher'),
						$locator->get('bizproc.service.activity.actionCatalogMap'),
					];
				},
			],
			'bizproc.service.activity.actionCatalogMap' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Service\\Activity\\ActionCatalogMap',
				// Explicit DI — no Service Locator inside ActionCatalogMap.
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();
					$logger = (new \Bitrix\Main\Diag\LoggerFactory())->createById('bizproc.service.activity.actionCatalogMap')
						?? new \Psr\Log\NullLogger();

					return [
						$locator->get('bizproc.runtime.activitysearcher.searcher'),
						$logger,
					];
				},
			],
			'bizproc.service.activity.capabilityCatalog' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Service\\Activity\\CapabilityCatalogService',
				// Explicit DI — no Service Locator inside CapabilityCatalogService.
				'constructorParams' => static function() {
					$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$locator->get('bizproc.service.activity.complex'),
						$locator->get('bizproc.runtime.activitysearcher.searcher'),
						$locator->get('bizproc.service.activity.actionCatalogMap'),
					];
				},
			],
			'bizproc.service.activity.nameGenerator' => [
				'className' => '\\Bitrix\\Bizproc\\Public\\Service\\Activity\\ActivityNameGeneratorService',
			],
			'bizproc.service.activity.entityFilter' => [
				'className' => \Bitrix\Bizproc\Public\Service\Activity\EntityFilterService::class,
			],
			'bizproc.workflow.stuck_pause.detector' => [
				'className' => \Bitrix\Bizproc\Internal\Service\WorkflowState\StuckPauseWorkflowDetector::class,
			],
			'bizproc.clear.stuck.workflow.command.handler' => [
				'className' => '\Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckWorkflowCommand\ClearStuckWorkflowCommandHandler',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getWorkflowStateRepository(),
					];
				},
			],
			'bizproc.clear.stuck.pause.workflow.command.handler' => [
				'className' => \Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckPauseWorkflowCommand\ClearStuckPauseWorkflowCommandHandler::class,
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getWorkflowStateRepository(),
						ServiceLocator::getInstance()->get('bizproc.workflow.stuck_pause.detector'),
					];
				},
			],
			'bizproc.service.trigger.scheduledTriggerService' => [
				'className' => \Bitrix\Bizproc\Public\Service\Trigger\ScheduledTriggerService::class,
			],
			'bizproc.service.trigger.scheduleSyncService' => [
				'className' => \Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleSyncService::class,
			],
			'bizproc.trigger.section.service' => [
				'className' => SectionService::class,
				'singleton' => false,
			],
			'bizproc.manager.trigger.scheduledTriggerAgent' => [
				'className' => \Bitrix\Bizproc\Infrastructure\Agent\Trigger\ScheduledTriggerAgent::class,
			],
			'bizproc.debugger.debug_session.repository.mapper' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\Mapper\DebugSessionOrmMapper::class,
			],
			'bizproc.debugger.debug_session.repository' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\Debugger\DebugSessionRepository::class,
				'constructorParams' => static function() {
					return [
						ServiceLocator::getInstance()->get('bizproc.debugger.debug_session.repository.mapper'),
					];
				},
			],
			'bizproc.debugger.debug_trace.repository.mapper' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\Mapper\DebugTraceOrmMapper::class,
			],
			'bizproc.debugger.debug_trace.repository' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\Debugger\DebugTraceRepository::class,
				'constructorParams' => static function() {
					return [
						ServiceLocator::getInstance()->get('bizproc.debugger.debug_trace.repository.mapper'),
					];
				},
			],
			'bizproc.debugger.debug_session.service' => [
				'className' => \Bitrix\Bizproc\Internal\Service\Debugger\DebugSessionService::class,
				'constructorParams' => static function() {
					return [
						'debugSessionRepository' => ServiceLocator::getInstance()->get('bizproc.debugger.debug_session.repository'),
						'debugTraceRepository' => ServiceLocator::getInstance()->get('bizproc.debugger.debug_trace.repository'),
					];
				},
			],
			'bizproc.debugger.debug.repository.mapper' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\Mapper\DebugOrmMapper::class,
			],
			'bizproc.debugger.debug.repository' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\Debugger\DebugRepository::class,
				'constructorParams' => static function() {
					return [
						ServiceLocator::getInstance()->get('bizproc.debugger.debug.repository.mapper'),
					];
				},
			],
			'bizproc.debugger.debug.service' => [
				'className' => \Bitrix\Bizproc\Internal\Service\Debugger\DebugService::class,
				'constructorParams' => static function() {
					return [
						'debugRepository' => ServiceLocator::getInstance()->get('bizproc.debugger.debug.repository'),
					];
				},
			],
			'bizproc.storage.field.value.repository' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Repository\\StorageItemRepository\\StorageFieldValueRepository',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getStorageFieldRepository(),
					];
				},
			],
			'bizproc.storage.field.validator' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Service\\StorageField\\StorageFieldValidatorService',
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getStorageFieldRepository(),
					];
				},
			],
			'bizproc.storage.item.model' => [
				'className' => '\\Bitrix\\Bizproc\\Internal\\Model\\StorageRecordDataTable',
			],
			'bizproc.ai_agent.managed_instance.repository.mapper' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\Mapper\ManagedAgentInstanceMapper::class,
			],
			'bizproc.ai_agent.managed_instance.repository' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentInstanceRepository::class,
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getManagedAgentInstanceRepositoryMapper(),
					];
				},
			],
			'bizproc.ai_agent.managed_resource.repository.mapper' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\Mapper\ManagedAgentResourceMapper::class,
			],
			'bizproc.ai_agent.managed_resource.repository' => [
				'className' => \Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentResourceRepository::class,
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Container::getManagedAgentResourceRepositoryMapper(),
					];
				},
			],
			/** @see \Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\ManagedAgentResourceRegistry::SERVICE_CODE */
			'bizproc.ai_agent.lifecycle.resource_registry' => [
				'className' => \Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\ManagedAgentResourceRegistry::class,
				// Keep this a singleton: the operation scope, the one time start markers and the template
				// classification live in the memory of one object for the request. A second instance would refuse
				// the start of a managed copy and would not see the operation of the orchestrator.
			],
			'bizproc.ai_agent.lifecycle.cleanup.schedule' => [
				'className' => \Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup\ScheduleCleanup::class,
			],
			'bizproc.ai_agent.lifecycle.cleanup.workflow' => [
				'className' => \Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup\WorkflowCleanup::class,
			],
			// One implementation serves both bot types as two separately constructed participants.
			'bizproc.ai_agent.lifecycle.cleanup.bizproc_bot' => [
				'className' => \Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup\BotCleanup::class,
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType::BizprocBot,
					];
				},
			],
			'bizproc.ai_agent.lifecycle.cleanup.openlines_bot' => [
				'className' => \Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup\BotCleanup::class,
				'constructorParams' => static function() {
					return [
						\Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType::OpenLinesBot,
					];
				},
			],
			'bizproc.ai_agent.lifecycle.cleanup.storage_scope' => [
				'className' => \Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup\StorageScopeCleanup::class,
			],
			/** @see \Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\SystemAiAgentLifecycleService::SERVICE_CODE */
			'bizproc.ai_agent.lifecycle.service' => [
				'className' => \Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\SystemAiAgentLifecycleService::class,
				'constructorParams' => static function() {
					$locator = ServiceLocator::getInstance();

					// The participants of a removal pass also answer which resource types are supported at all,
					// so the capability validator is built from this very list and is not registered separately.
					return [
						[
							$locator->get('bizproc.ai_agent.lifecycle.cleanup.schedule'),
							$locator->get('bizproc.ai_agent.lifecycle.cleanup.workflow'),
							$locator->get('bizproc.ai_agent.lifecycle.cleanup.bizproc_bot'),
							$locator->get('bizproc.ai_agent.lifecycle.cleanup.openlines_bot'),
							$locator->get('bizproc.ai_agent.lifecycle.cleanup.storage_scope'),
						],
					];
				},
			],
		],
	],
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'bizproc-template',
					'provider' => [
						'moduleId' => 'bizproc',
						'className' => TemplateProvider::class,
					],
				],
				[
					'entityId' => 'bizproc-start-workflow-template',
					'provider' => [
						'moduleId' => 'bizproc',
						'className' => StartWorkflowTemplateProvider::class,
					],
				],
				[
					'entityId' => 'bizproc-script-template',
					'provider' => [
						'moduleId' => 'bizproc',
						'className' => ScriptTemplateProvider::class,
					],
				],
				[
					'entityId' => 'bizproc-automation-template',
					'provider' => [
						'moduleId' => 'bizproc',
						'className' => AutomationTemplateProvider::class,
					],
				],
				[
					'entityId' => 'bizproc-document',
					'provider' => [
						'moduleId' => 'bizproc',
						'className' => DocumentProvider::class,
					],
				],
				[
					'entityId' => 'bizproc-system',
					'provider' => [
						'moduleId' => 'bizproc',
						'className' => SystemProvider::class,
					],
				],
				[
					'entityId' => 'bizproc-document-type',
					'provider' => [
						'moduleId' => 'bizproc',
						'className' => DocumentTypeProvider::class,
					],
				],
				[
					'entityId' => 'bizproc-storage',
					'provider' => [
						'moduleId' => 'bizproc',
						'className' => StorageProvider::class,
					],
				],
				[
					// Literal, not ::class: a class constant read here would autoload bizproc before its
					// own autoloader is up, fataling the Messenger worker.
					'entityId' => 'bizproc-access-template',
					'provider' => [
						'moduleId' => 'bizproc',
						'className' => AccessTemplateProvider::class,
					],
				],
			],
			'extensions' => ['bizproc.entity-selector'],
		],
		'readonly' => true,
	],
	'bizproc.field-types' => [
		'value' => [
			'types' => [
				['type' => 'string', 'extension' => 'bizproc.fields.string'],
				['type' => 'text', 'extension' => 'bizproc.fields.text'],
				['type' => 'int', 'extension' => 'bizproc.fields.int'],
				['type' => 'bool', 'extension' => 'bizproc.fields.bool'],
				['type' => 'double', 'extension' => 'bizproc.fields.double'],
				['type' => 'date', 'extension' => 'bizproc.fields.date'],
				['type' => 'datetime', 'extension' => 'bizproc.fields.datetime'],
				['type' => 'select', 'extension' => 'bizproc.fields.select'],
			],
			'extensions' => [],
		],
		'readonly' => true,
	],
	'ui.uploader' => [
		'value' => [
			'allowUseControllers' => true,
		],
		'readonly' => true,
	],
	'messenger' => [
		'value' => [
			'brokers' => [
				'workflow_db' => [
					'type' => DbBroker::TYPE_CODE,
					'params' => [
						'table' => WorkflowStartMessageTable::class,
					]
				],
				'workflow_resume_db' => [
					'type' => DbBroker::TYPE_CODE,
					'params' => [
						'table' => WorkflowResumeMessageTable::class,
					]
				]
			],
			'queues' => array_merge(
				[
					'start_workflow_queue' => [
						'broker' => 'workflow_db',
						'handler' => WorkflowStartReceiver::class,
					],
					'resume_workflow_queue' => [
						'broker' => 'workflow_resume_db',
						'handler' => WorkflowResumeReceiver::class,
						'limit' => 5,
					],
					'scheduled_trigger_queue' => [
						'broker' => 'workflow_db',
						'handler' => \Bitrix\Bizproc\Internal\Service\Trigger\Messenger\Receiver\ScheduledTriggerReceiver::class,
					],
				],
				$pauseQueues,
			),
		],
		'readonly' => true,
	],
];
