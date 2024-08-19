<?php
return [
	'controllers' => [
		'value' => [
			'namespaces' => [
				'\\Bitrix\\AI\\Controller' => 'api'
			],
			'defaultNamespace' => '\\Bitrix\\AI\\Controller',
		],
		'readonly' => true,
	],
	'aiproxy' => [
		'value' => [
			'serverListEndpoint' => 'https://ai-proxy.bitrix.info/settings/config.json',
		],
		'readonly' => true,
	],
];
