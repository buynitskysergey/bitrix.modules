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
			\Bitrix\Mobile\Feature\MenuFeature::class,
			\Bitrix\Mobile\Feature\MobileMarketFeature::class,
			\Bitrix\Mobile\Feature\SettingsV2Feature::class,
			\Bitrix\Mobile\Feature\SecuritySettingsFeature::class,
			\Bitrix\Mobile\Feature\AhaMomentFeature::class,
			\Bitrix\Mobile\Feature\ImplementationSpecialistsFeature::class,
			\Bitrix\Mobile\Feature\PersonalAccountFeature::class,
			\Bitrix\Mobile\Feature\IntegratorFeature::class,
			\Bitrix\Mobile\Feature\PurchasesFeature::class,
			\Bitrix\Mobile\Feature\TariffMenuFeature::class,
			\Bitrix\Mobile\Feature\TariffMenuPurchaseCtaFeature::class,
			\Bitrix\Mobile\Feature\TariffMenuDemoModeFeature::class,
			\Bitrix\Mobile\Feature\UsersFloatingBannerFeature::class,
			\Bitrix\Mobile\Feature\BitrixAnswersFeature::class,
		],
		'readonly' => true,
	],
];
