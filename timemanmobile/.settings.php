<?php

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\TimemanMobile\\Infrastructure\\Controllers',
			'restIntegration' => [
				'enabled' => true,
			],
		],
		'readonly' => true,
	],
	'feature-flags' => [
		'value' => [
			Bitrix\TimemanMobile\Public\Features\WorkReportsFeature::class,
			Bitrix\TimemanMobile\Public\Features\WorkReportsWithoutAiFeature::class,
		],
		'readonly' => true,
	],
];
