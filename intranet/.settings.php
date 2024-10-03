<?php
return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Intranet\\Controller',
			'restIntegration' => [
				'enabled' => true,
			],
		],
		'readonly' => true,
	],
	'ui.selector' => [
		'value' => [
			'intranet.selector'
		],
		'readonly' => true,
	],
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'department',
					'provider' => [
						'moduleId' => 'intranet',
						'className' => '\\Bitrix\\Intranet\\Integration\\UI\\EntitySelector\\DepartmentProvider'
					],
				],
			],
			'extensions' => ['intranet.entity-selector'],
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			'intranet.customSection.manager' => [
				'className' => '\\Bitrix\\Intranet\\CustomSection\\Manager',
			],
			'intranet.option.mobile_app' => [
				'constructor' => function () {
					return new \Bitrix\Intranet\Service\MobileAppSettings(
						new \Bitrix\Intranet\Service\IntranetOption()
					);
				},
			],
			'intranet.repository.iblock.department' => [
				'className' => \Bitrix\Intranet\Repository\IblockDepartmentRepository::class,
			],
			'intranet.repository.hr.department' => [
				'className' => \Bitrix\Intranet\Repository\HrDepartmentRepository::class,
			],
			'intranet.repository.department' => [
				'constructor' => function () {
					if (\Bitrix\Main\Loader::includeModule('humanresources')
					&& (new \Bitrix\Intranet\Service\IntranetOption)->get('humanresources_enabled') === 'Y')
					{
						return new \Bitrix\Intranet\Repository\HrDepartmentRepository();
					}

					return new \Bitrix\Intranet\Repository\IblockDepartmentRepository();
				}
			],
			'intranet.repository.invitation' => [
				'className' => \Bitrix\Intranet\Repository\InvitationRepository::class,
			],
		],
		'readonly' => true,
	],
];
