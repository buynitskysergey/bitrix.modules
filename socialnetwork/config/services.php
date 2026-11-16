<?php

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Socialnetwork\Collab\Control\CollabService;
use Bitrix\Socialnetwork\Collab\Control\Member\CollabMemberFacade;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\V2\Internal\Access\Factory\ControllerFactory;
use Bitrix\Socialnetwork\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Repository\ChatMemberRepository;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Repository\ChatMemberRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ConvertProgressRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ConvertProgressRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\FileRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\InMemoryFileRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\InMemoryUserRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectOwnerRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectOwnerRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\FavoritesRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\FavoritesRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ScrumRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\ScrumRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupPinRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupPinRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupCounterRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupCounterRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupFilterRepository;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupFilterRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\Factory\HandlerFactory;
use Bitrix\Socialnetwork\V2\Internal\Service\Convert\Handler\Factory\HandlerFactoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\UserService;
use Bitrix\Socialnetwork\V2\Internal\Service\UserServiceInterface;

return [
	'value' => [
		// region services
		'socialnetwork.collab.member.facade' => [
			'className' => \Bitrix\Socialnetwork\Collab\Control\Member\CollabMemberFacade::class,
		],
		'socialnetwork.group.member.service' => [
			'className' => \Bitrix\Socialnetwork\Control\Member\GroupMemberService::class,
		],
		'socialnetwork.group.service' => [
			'className' => \Bitrix\Socialnetwork\Control\GroupService::class,
		],
		'socialnetwork.collab.service' => [
			'className' => \Bitrix\Socialnetwork\Collab\Control\CollabService::class,
		],
		'socialnetwork.collab.option.service' => [
			'className' => \Bitrix\Socialnetwork\Collab\Control\Option\OptionService::class,
		],
		'socialnetwork.collab.activity.service' => [
			'className' => \Bitrix\Socialnetwork\Collab\Control\Activity\LastActivityService::class,
		],
		'socialnetwork.collab.log.service' => [
			'className' => \Bitrix\Socialnetwork\Collab\Control\Log\LogEntryService::class,
		],
		'socialnetwork.collab.invitation.service' => [
			'className' => \Bitrix\Socialnetwork\Collab\Control\Invite\InvitationService::class,
		],
		'socialnetwork.collab.im.date.range.formatter' => [
			'className' => \Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreate\DateRangeFormatter::class,
		],
		'socialnetwork.collab.im.project.members.enumeration' => [
			'className' => \Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\ProjectMembersEnumeration::class,
		],
		'socialnetwork.collab.im.projectattach.builder' => [
			'constructor' => static fn() => new \Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreate\ProjectAttachBuilder(
				ServiceLocator::getInstance()->get('socialnetwork.collab.im.date.range.formatter'),
				ServiceLocator::getInstance()->get('socialnetwork.collab.im.project.members.enumeration'),
			),
		],
		'socialnetwork.onboarding.job.repository' => [
			'className' => \Bitrix\Socialnetwork\Collab\Onboarding\Internals\Repository\JobRepository::class,
		],
		'socialnetwork.onboarding.job.cache' => [
			'className' => \Bitrix\Socialnetwork\Collab\Onboarding\Internals\Repository\Cache\JobCache::class,
		],
		'socialnetwork.onboarding.batch.job.executor' => [
			'className' => \Bitrix\Socialnetwork\Collab\Onboarding\Execution\Executor\BatchJobExecutor::class,
		],
		'socialnetwork.onboarding.queue.service' => [
			'className' => \Bitrix\Socialnetwork\Collab\Onboarding\Service\QueueService::class,
		],
		'socialnetwork.onboarding.queue.provider' => [
			'className' => \Bitrix\Socialnetwork\Collab\Onboarding\Provider\QueueProvider::class,
		],
		'socialnetwork.onboarding.promotion.service' => [
			'className' => \Bitrix\Socialnetwork\Collab\Onboarding\Integration\Im\Promotion\PromotionService::class,
		],
		// endregion
		// region V2 DI
		CollabMemberFacade::class => [
			'constructor' => static fn() => ServiceLocator::getInstance()->get('socialnetwork.collab.member.facade'),
		],
		UserRepositoryInterface::class => [
			'className' => InMemoryUserRepository::class,
		],
		FileRepositoryInterface::class => [
			'className' => InMemoryFileRepository::class,
		],
		ProjectMemberRepositoryInterface::class => [
			'className' => ProjectMemberRepository::class,
		],
		ProjectOwnerRepositoryInterface::class => [
			'className' => ProjectOwnerRepository::class,
		],
		ProjectRepositoryInterface::class => [
			'className' => ProjectRepository::class,
		],
		ChatMemberRepositoryInterface::class => [
			'className' => ChatMemberRepository::class,
		],
		ScrumRepositoryInterface::class => [
			'className' => ScrumRepository::class,
		],
		FavoritesRepositoryInterface::class => [
			'className' => FavoritesRepository::class,
		],
		WorkgroupPinRepositoryInterface::class => [
			'className' => WorkgroupPinRepository::class,
		],
		WorkgroupFilterRepositoryInterface::class => [
			'className' => WorkgroupFilterRepository::class,
		],
		WorkgroupCounterRepositoryInterface::class => [
			'className' => WorkgroupCounterRepository::class,
		],
		UserServiceInterface::class => [
			'className' => UserService::class,
		],
		HandlerFactoryInterface::class => [
			'className' => HandlerFactory::class,
		],
		ControllerFactoryInterface::class => [
			'constructor' => static fn(): ControllerFactoryInterface => ControllerFactory::getInstance(),
		],
		CollabRegistry::class => [
			'constructor' => static fn(): CollabRegistry => CollabRegistry::getInstance(),
		],
		CollabService::class => [
			'constructor' => static fn(): CollabService => ServiceLocator::getInstance()->get('socialnetwork.collab.service'),
		],
		ConvertProgressRepositoryInterface::class => [
			'className' => ConvertProgressRepository::class,
		],
		// endregion

		Container::class => [
			'constructor' => fn (): Container => Container::getInstance(),
		],
	],
];
