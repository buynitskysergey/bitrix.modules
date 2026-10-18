<?php

use Bitrix\Timeman\Repository\Worktime\WorktimeRepository;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Repository\FileRepository;
use Bitrix\Timeman\V2\Internal\Repository\FileRepositoryInterface;
use Bitrix\Timeman\V2\Internal\Repository\InMemoryUserRepository;
use Bitrix\Timeman\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Timeman\V2\Internal\Service\AccessService;
use Bitrix\Timeman\V2\Internal\Service\UserService;
use Bitrix\Timeman\V2\Internal\Service\UserServiceInterface;

return [
	'value' => [
		UserRepositoryInterface::class => [
			'className' => InMemoryUserRepository::class,
		],
		FileRepositoryInterface::class => [
			'className' => FileRepository::class,
		],
		UserServiceInterface::class => [
			'className' => UserService::class,
		],
		Container::class => [
			'constructor' => static fn (): Container => Container::getInstance(),
		],
		// The composition root of the legacy bridges: the global user and the legacy dependency
		// manager are read here alone, so no v2 service or provider reaches for them itself. The
		// user is read per call, not per construction - see the resolver note on AccessService.
		AccessService::class => [
			'constructor' => static fn (): AccessService => new AccessService(
				static fn () => DependencyManager::getInstance()->getUserPermissionsManager($GLOBALS['USER'] ?? null),
			),
		],
		WorktimeRepository::class => [
			'constructor' => static fn (): WorktimeRepository => DependencyManager::getInstance()->getWorktimeRepository(),
		],
	],
];
