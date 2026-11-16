<?php

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Internal\Integration\Intranet\ToolAvailabilityService;
use Bitrix\StaffTrack\Internal\Integration\Timeman\TimeZoneService;
use Bitrix\Timeman\Helper\TimeHelper;

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\StaffTrack\\Controller',
			'restIntegration' => [
				'enabled' => false,
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
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'stafftrack-office',
					'provider' => [
						'moduleId' => 'stafftrack',
						'className' => '\\Bitrix\\StaffTrack\\Internal\\Integration\\UI\\EntitySelector\\OfficeSelectorProvider',
					],
				],
			],
		],
		'readonly' => true,
	],
	// The composition root of the services over the legacy singletons of other modules: a singleton is taken here,
	// by a factory called at every reading, so building such a service includes no module.
	'services' => [
		'value' => [
			TimeZoneService::class => [
				'constructor' => static fn (): TimeZoneService => new TimeZoneService(
					static fn (): ?TimeHelper => Loader::includeModule('timeman') ? TimeHelper::getInstance() : null,
				),
			],
			ToolAvailabilityService::class => [
				'constructor' => static fn (): ToolAvailabilityService => new ToolAvailabilityService(
					static fn (): ?ToolsManager => Loader::includeModule('intranet')
						? ToolsManager::getInstance()
						: null,
				),
			],
		],
		'readonly' => true,
	],
];
