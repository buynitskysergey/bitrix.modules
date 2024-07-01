<?php
return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Main\\Controller',
			'namespaces' => [
				'\\Bitrix\\Main\\Controller' => 'api',
			],
			'restIntegration' => [
				'enabled' => true,
				'hideModuleScope' => true,
				'scopes' => [
					'userfieldconfig',
				],
			],
		],
		'readonly' => true,
	],
	'console' => [
		'value' => [
			'commands' => [
				\Bitrix\Main\Cli\OrmAnnotateCommand::class,
			],
		],
		'readonly' => true,
	],
];
