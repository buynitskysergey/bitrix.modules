<?php

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\BIConnectorMobile\\Controller',
			'restIntegration' => [
				'enabled' => true,
			],
		],
		'readonly' => true,
	],
	'feature-flags' => [
		'value' => [
			Bitrix\BIConnectorMobile\Public\Features\MobileBIFeature::class,
		],
		'readonly' => true,
	],
];
