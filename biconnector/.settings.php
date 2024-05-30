<?php

use Bitrix\BIConnector\Integration\UI\EntitySelector\SupersetDashboardProvider;
use Bitrix\BIConnector\Integration\UI\EntitySelector\SupersetDashboardTagProvider;

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\BIConnector\\Controller',
			'restIntegration' => [
				'enabled' => true,
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
					'entityId' => 'biconnector-superset-dashboard-tag',
					'provider' => [
						'moduleId' => 'biconnector',
						'className' => SupersetDashboardTagProvider::class,
					],
				],
			],
			'extensions' => ['biconnector.entity-selector'],
		],
		'readonly' => true,
	],
];
