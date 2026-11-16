<?php

return [
	'controllers' => [
		'value' => [
			'namespaces' => [
				'\\Bitrix\\StaffTrackMobile\\Infrastructure\\Controllers' => 'v2',
			],
			'defaultNamespace' => '\\Bitrix\\StaffTrackMobile\\Controller',
			'restIntegration' => [
				'enabled' => false,
			],
		],
		'readonly' => true,
	],
	'feature-flags' => [
		'value' => [
			\Bitrix\StaffTrackMobile\Public\Features\CheckInFeature::class,
			\Bitrix\StaffTrackMobile\Public\Features\CheckInV3StaffSelectorFeature::class,
			\Bitrix\StaffTrackMobile\Public\Features\PresenceListFeature::class,
			\Bitrix\StaffTrackMobile\Public\Features\WorkReportCounterFeature::class,
		],
		'readonly' => true,
	],
];
