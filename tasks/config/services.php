<?php

use Bitrix\Tasks\Control\Log\TaskLogService;
use Bitrix\Tasks\Deadline\Internals\Repository\Cache\Managed\CacheDeadlineUserOptionRepository;
use Bitrix\Tasks\Deadline\Internals\Repository\DeadlineUserOptionRepositoryInterface;
use Bitrix\Tasks\Flow\Control\Command\AddCommandHandler;
use Bitrix\Tasks\Flow\Control\Command\DeleteCommandHandler;
use Bitrix\Tasks\Flow\Control\Command\UpdateCommandHandler;
use Bitrix\Tasks\Flow\Control\FlowService;
use Bitrix\Tasks\Flow\Efficiency\EfficiencyService;
use Bitrix\Tasks\Flow\Integration\AI\Control\AdviceService;
use Bitrix\Tasks\Flow\Integration\AI\Control\CollectedDataService;
use Bitrix\Tasks\Flow\Integration\Socialnetwork\GroupService;
use Bitrix\Tasks\Flow\Kanban\BizProcService;
use Bitrix\Tasks\Flow\Migration\Access\Repository\RoleRepository;
use Bitrix\Tasks\Flow\Migration\Access\Repository\RoleRepositoryInterface;
use Bitrix\Tasks\Flow\Provider\FlowMemberFacade;
use Bitrix\Tasks\Flow\Template\Access\Permission\TemplatePermissionService;
use Bitrix\Tasks\Internals\Counter\CounterService;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\Internals\UserOption\Service\AutoMuteService;
use Bitrix\Tasks\Onboarding\Promo\Repository\Cache\CacheInviteToMobileRepository;
use Bitrix\Tasks\Onboarding\Promo\Repository\InviteToMobileRepositoryInterface;
use Bitrix\Tasks\Replication\Replicator\RegularTemplateTaskReplicator;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactory;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\InMemoryCrmItemRepository;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Repository\DiskFileRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Repository\InMemoryDiskFileRepository;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository\StructureRepository;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository\StructureRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\AbsenceService;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotification;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSender;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\Repository\MessageRepository;
use Bitrix\Tasks\V2\Internal\Integration\Im\Repository\MessageRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Repository\InMemoryPlacementRepository;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Repository\PlacementRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Repository\EmailRepository;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Repository\EmailRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\LoggerInterface;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRepository;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRevisionRepository;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRevisionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\CheckListUserOptionRepository;
use Bitrix\Tasks\V2\Internal\Repository\CheckListUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\CounterRepository;
use Bitrix\Tasks\V2\Internal\Repository\CounterRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\DeadlineChangeLogRepository;
use Bitrix\Tasks\V2\Internal\Repository\DeadlineChangeLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\ElapsedTimeReadRepository;
use Bitrix\Tasks\V2\Internal\Repository\ElapsedTimeReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\ElapsedTimeRepository;
use Bitrix\Tasks\V2\Internal\Repository\ElapsedTimeRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\FavoriteTaskRepository;
use Bitrix\Tasks\V2\Internal\Repository\FavoriteTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\FileRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\FlowMemberRepository;
use Bitrix\Tasks\V2\Internal\Repository\FlowMemberRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\FlowRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\GanttLinkRepository;
use Bitrix\Tasks\V2\Internal\Repository\GanttLinkRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryChatRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryFileRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryFlowRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryGroupRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryRelatedTaskRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryStageRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryCheckListEntityRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemorySubTaskRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryTariffRestrictionRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryTaskParameterRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryTaskRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryTaskTagRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryTaskUserOptionRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryTimerRepository;
use Bitrix\Tasks\V2\Internal\Repository\SystemHistoryRepository;
use Bitrix\Tasks\V2\Internal\Repository\SystemHistoryRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskAccessRequestRepository;
use Bitrix\Tasks\V2\Internal\Repository\TaskAccessRequestRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\AccessEntityRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\AccessEntityRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\InMemoryTemplateRepository;
use Bitrix\Tasks\V2\Internal\Repository\InMemoryUserRepository;
use Bitrix\Tasks\V2\Internal\Repository\OptionRepository;
use Bitrix\Tasks\V2\Internal\Repository\OptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\ParentTaskRepository;
use Bitrix\Tasks\V2\Internal\Repository\ParentTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\PlannerRepository;
use Bitrix\Tasks\V2\Internal\Repository\PlannerRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\RelatedTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\ReminderReadRepository;
use Bitrix\Tasks\V2\Internal\Repository\ReminderReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\ReminderRepository;
use Bitrix\Tasks\V2\Internal\Repository\ReminderRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\StageRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\CheckListEntityRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\SubTaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TariffRestrictionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskHistoryGridRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskHistoryGridRepository;
use Bitrix\Tasks\V2\Internal\Repository\TaskHistoryRepository;
use Bitrix\Tasks\V2\Internal\Repository\TaskHistoryRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskLogRepository;
use Bitrix\Tasks\V2\Internal\Repository\TaskLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskMemberRepository;
use Bitrix\Tasks\V2\Internal\Repository\TaskMemberRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskParameterRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepository;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\InMemoryTemplateReplicateParamsRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReplicateParamsRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskResultRepository;
use Bitrix\Tasks\V2\Internal\Repository\TaskResultRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskScenarioRepository;
use Bitrix\Tasks\V2\Internal\Repository\TaskScenarioRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskStageRepository;
use Bitrix\Tasks\V2\Internal\Repository\TaskStageRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskTagRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\ParentTemplateRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\ParentTemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\RelatedTaskTemplateRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\RelatedTaskTemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\SubTemplateRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\SubTemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateParameterRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateParameterRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplatePermissionRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplatePermissionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReadRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRecentRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRecentRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateTagRepository;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateTagRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TimerRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserFieldSchemeRepository;
use Bitrix\Tasks\V2\Internal\Repository\UserFieldSchemeRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserOptionRepository;
use Bitrix\Tasks\V2\Internal\Repository\UserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\ViewedAbsenceRepository;
use Bitrix\Tasks\V2\Internal\Repository\ViewedAbsenceRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\ViewedUserRepository;
use Bitrix\Tasks\V2\Internal\Repository\ViewedUserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\ViewRepository;
use Bitrix\Tasks\V2\Internal\Repository\ViewRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConfigConsistencyResolver;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressController;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Ping\ChatMessagePing;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Ping\ForumCommentPing;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Ping\PingActionInterface;

return [
	'value' => [
		// region services
		'tasks.flow.member.facade' => [
			'className' => FlowMemberFacade::class,
		],
		'tasks.flow.command.addCommandHandler' => [
			'className' => AddCommandHandler::class,
		],
		'tasks.flow.command.updateCommandHandler' => [
			'className' => UpdateCommandHandler::class,
		],
		'tasks.flow.command.deleteCommandHandler' => [
			'className' => DeleteCommandHandler::class,
		],
		'tasks.flow.socialnetwork.project.service' => [
			'className' => GroupService::class,
		],
		'tasks.flow.kanban.bizproc.service' => [
			'className' => BizProcService::class,
		],
		'tasks.flow.template.permission.service' => [
			'className' => TemplatePermissionService::class,
		],
		'tasks.flow.notification.service' => [
			'className' => \Bitrix\Tasks\Flow\Notification\NotificationService::class,
		],
		'tasks.flow.service' => [
			'className' => FlowService::class,
		],
		'tasks.flow.efficiency.service' => [
			'className' => EfficiencyService::class,
		],
		'tasks.flow.copilot.collected.data.service' => [
			'className' => CollectedDataService::class,
		],
		'tasks.flow.copilot.advice.service' => [
			'className' => AdviceService::class,
		],
		'tasks.control.log.task.service' => [
			'className' => TaskLogService::class,
		],
		'tasks.regular.replicator' => [
			'className' => RegularTemplateTaskReplicator::class,
		],
		'tasks.user.option.automute.service' => [
			'className' => AutoMuteService::class,
		],
		// endregion
		// region DI
		ControllerFactoryInterface::class => [
			'constructor' => static fn(): ControllerFactoryInterface => ControllerFactory::getInstance(),
		],
		'tasks.task.registry' => [
			'constructor' => static fn(): TaskRegistry => TaskRegistry::getInstance(),
		],
		FileRepositoryInterface::class => [
			'className' => InMemoryFileRepository::class,
		],
		GroupRepositoryInterface::class => [
			'className' => InMemoryGroupRepository::class,
		],
		TaskRepositoryInterface::class => [
			'className' => InMemoryTaskRepository::class,
		],
		StageRepositoryInterface::class => [
			'className' => InMemoryStageRepository::class,
		],
		TemplateRepositoryInterface::class => [
			'className' => InMemoryTemplateRepository::class,
		],
		FlowRepositoryInterface::class => [
			'className' => InMemoryFlowRepository::class,
		],
		CheckListEntityRepositoryInterface::class => [
			'className' => InMemoryCheckListEntityRepository::class,
		],
		SubTaskRepositoryInterface::class => [
			'className' => InMemorySubTaskRepository::class,
		],
		RelatedTaskRepositoryInterface::class => [
			'className' => InMemoryRelatedTaskRepository::class,
		],
		CheckListRepositoryInterface::class => [
			'className' => CheckListRepository::class,
		],
		CheckListRevisionRepositoryInterface::class => [
			'className' => CheckListRevisionRepository::class,
		],
		CheckListUserOptionRepositoryInterface::class => [
			'className' => CheckListUserOptionRepository::class,
		],
		UserRepositoryInterface::class => [
			'className' => InMemoryUserRepository::class,
		],
		EgressInterface::class => [
			'className' => EgressController::class,
		],
		ChatRepositoryInterface::class => [
			'className' => InMemoryChatRepository::class,
		],
		DiskFileRepositoryInterface::class => [
			'className' => InMemoryDiskFileRepository::class,
		],
		TaskResultRepositoryInterface::class => [
			'className' => TaskResultRepository::class,
		],
		TaskLogRepositoryInterface::class => [
			'className' => TaskLogRepository::class,
		],
		ConsistencyResolverInterface::class => [
			'className' => ConfigConsistencyResolver::class,
		],
		FavoriteTaskRepositoryInterface::class => [
			'className' => FavoriteTaskRepository::class,
		],
		TaskMemberRepositoryInterface::class => [
			'className' => TaskMemberRepository::class,
		],
		TaskUserOptionRepositoryInterface::class => [
			'className' => InMemoryTaskUserOptionRepository::class,
		],
		UserOptionRepositoryInterface::class => [
			'className' => UserOptionRepository::class,
		],
		DeadlineUserOptionRepositoryInterface::class => [
			'className' => CacheDeadlineUserOptionRepository::class,
		],
		TimerRepositoryInterface::class => [
			'className' => InMemoryTimerRepository::class,
		],
		PlannerRepositoryInterface::class => [
			'className' => PlannerRepository::class,
		],
		ElapsedTimeRepositoryInterface::class => [
			'className' => ElapsedTimeRepository::class,
		],
		ElapsedTimeReadRepositoryInterface::class => [
			'className' => ElapsedTimeReadRepository::class,
		],
		InviteToMobileRepositoryInterface::class => [
			'className' => CacheInviteToMobileRepository::class,
		],
		ViewRepositoryInterface::class => [
			'className' => ViewRepository::class,
		],
		TaskStageRepositoryInterface::class => [
			'className' => TaskStageRepository::class,
		],
		TaskReadRepositoryInterface::class => [
			'className' => TaskReadRepository::class,
		],
		TaskParameterRepositoryInterface::class => [
			'className' => InMemoryTaskParameterRepository::class,
		],
		TaskScenarioRepositoryInterface::class => [
			'className' => TaskScenarioRepository::class,
		],
		DeadlineChangeLogRepositoryInterface::class => [
			'className' => DeadlineChangeLogRepository::class,
		],
		ReminderRepositoryInterface::class => [
			'className' => ReminderRepository::class,
		],
		ReminderReadRepositoryInterface::class => [
			'className' => ReminderReadRepository::class,
		],
		TemplateReplicateParamsRepositoryInterface::class => [
			'className' => InMemoryTemplateReplicateParamsRepository::class,
		],
		ChatNotificationInterface::class => [
			'className' => ChatNotification::class,
		],
		CrmItemRepositoryInterface::class => [
			'className' => InMemoryCrmItemRepository::class,
		],
		RoleRepositoryInterface::class => [
			'className' => RoleRepository::class,
		],
		OptionRepositoryInterface::class => [
			'className' => OptionRepository::class,
		],
		TaskTagRepositoryInterface::class => [
			'className' => InMemoryTaskTagRepository::class,
		],
		GanttLinkRepositoryInterface::class => [
			'className' => GanttLinkRepository::class,
		],
		TariffRestrictionRepositoryInterface::class => [
			'className' => InMemoryTariffRestrictionRepository::class,
		],
		ParentTaskRepositoryInterface::class => [
			'className' => ParentTaskRepository::class,
		],
		TaskHistoryRepositoryInterface::class => [
			'className' => TaskHistoryRepository::class,
		],
		MessageRepositoryInterface::class => [
			'className' => MessageRepository::class,
		],
		CounterRepositoryInterface::class => [
			'className' => CounterRepository::class,
		],
		MessageSenderInterface::class => [
			'className' => MessageSender::class,
		],
		PlacementRepositoryInterface::class => [
			'className' => InMemoryPlacementRepository::class,
		],
		TaskHistoryGridRepositoryInterface::class => [
			'className' => TaskHistoryGridRepository::class,
		],
		EmailRepositoryInterface::class => [
			'className' => EmailRepository::class,
		],
		UserFieldSchemeRepositoryInterface::class => [
			'className' => UserFieldSchemeRepository::class,
		],
		TemplateReadRepositoryInterface::class => [
			'className' => TemplateReadRepository::class,
		],
		TemplateTagRepositoryInterface::class => [
			'className' => TemplateTagRepository::class,
		],
		TemplatePermissionRepositoryInterface::class => [
			'className' => TemplatePermissionRepository::class,
		],
		TemplateRecentRepositoryInterface::class => [
			'className' => TemplateRecentRepository::class,
		],
		SubTemplateRepositoryInterface::class => [
			'className' => SubTemplateRepository::class,
		],
		AccessEntityRepositoryInterface::class => [
			'className' => AccessEntityRepository::class,
		],
		StructureRepositoryInterface::class => [
			'className' => StructureRepository::class,
		],
		RelatedTaskTemplateRepositoryInterface::class => [
			'className' => RelatedTaskTemplateRepository::class,
		],
		ParentTemplateRepositoryInterface::class => [
			'className' => ParentTemplateRepository::class,
		],
		SystemHistoryRepositoryInterface::class => [
			'className' => SystemHistoryRepository::class,
		],
		LoggerInterface::class => [
			'className' => Logger::class,
		],
		ViewedUserRepositoryInterface::class => [
			'className' => ViewedUserRepository::class,
		],
		FlowMemberRepositoryInterface::class => [
			'className' => FlowMemberRepository::class,
		],
		TemplateParameterRepositoryInterface::class => [
			'className' => TemplateParameterRepository::class,
		],
		TaskAccessRequestRepositoryInterface::class => [
			'className' => TaskAccessRequestRepository::class,
		],
		ViewedAbsenceRepositoryInterface::class => [
			'className' => ViewedAbsenceRepository::class,
		],
		AbsenceService::class => [
			'className' => AbsenceService::class,
		],
		// endregion

		// region counters
		CounterService::class => [
			'constructor' => fn (): CounterService => CounterService::getInstance(),
		],
		Bitrix\Tasks\Internals\Counter\Collector\ProjectCollector::class => [
			'constructor' => fn (): Bitrix\Tasks\Internals\Counter\Collector\ProjectCollector
				=> Bitrix\Tasks\V2\FormV2Feature::isOn()
					? new Bitrix\Tasks\V2\Internal\Service\Counter\Collector\ProjectCollector()
					: new Bitrix\Tasks\Internals\Counter\Collector\ProjectCollector()
				,
		],
		Bitrix\Tasks\Internals\Counter\Event\GarbageCollector::class => [
			'constructor' => fn (): Bitrix\Tasks\Internals\Counter\Event\GarbageCollector
				=> Bitrix\Tasks\V2\FormV2Feature::isOn()
					? new Bitrix\Tasks\V2\Internal\Service\Counter\Collector\GarbageCollector()
					: new Bitrix\Tasks\Internals\Counter\Event\GarbageCollector()
				,
		],
		PingActionInterface::class => [
			'constructor' => fn (): PingActionInterface => Bitrix\Tasks\V2\FormV2Feature::isOn()
				? new ChatMessagePing()
				: new ForumCommentPing(),
		],
		// endregion

		Container::class => [
			'constructor' => fn (): Container => Container::getInstance(),
		],
	],
];
