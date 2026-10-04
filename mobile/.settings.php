<?php

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Mobile\\Controller',
			'restIntegration' => [
				'enabled' => true,
			],
		],
		'readonly' => true,
	],
	'feature-flags' => [
		'value' => [
			\Bitrix\Mobile\Feature\SupportFeature::class,
			\Bitrix\Mobile\Feature\WhatsNewFeature::class,
			\Bitrix\Mobile\Feature\OnboardingFeature::class,
			\Bitrix\Mobile\Feature\OnboardingPushFeature::class,
			\Bitrix\Mobile\Feature\MenuFeature::class,
			\Bitrix\Mobile\Feature\MobileMarketFeature::class,
			\Bitrix\Mobile\Feature\SettingsV2Feature::class,
			\Bitrix\Mobile\Feature\SecuritySettingsFeature::class,
			\Bitrix\Mobile\Feature\AhaMomentFeature::class,
			\Bitrix\Mobile\Feature\PersonalAccountFeature::class,
		],
		'readonly' => true,
	],
];
