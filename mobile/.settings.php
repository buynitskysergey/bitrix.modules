<?php

return [
	'controllers' => [
		'value' => [
			'namespaces' => [
				'Bitrix\\Mobile\\Infrastructure\\Controller' => 'v2',
			],
			'defaultNamespace' => '\\Bitrix\\Mobile\\Controller',
			'restIntegration' => [
				'enabled' => true,
			],
		],
		'readonly' => true,
	],
	'feature-flags' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Mobile\\Public\\Features',
			\Bitrix\Mobile\Feature\SupportFeature::class,
			\Bitrix\Mobile\Feature\WhatsNewFeature::class,
			\Bitrix\Mobile\Feature\OnboardingFeature::class,
			\Bitrix\Mobile\Feature\MenuFeature::class,
			\Bitrix\Mobile\Feature\MobileMarketFeature::class,
			\Bitrix\Mobile\Feature\SettingsV2Feature::class,
			\Bitrix\Mobile\Feature\SecuritySettingsFeature::class,
			\Bitrix\Mobile\Feature\AhaMomentFeature::class,
			\Bitrix\Mobile\Feature\MobileQualificationPortalDefaultPresetFeature::class,
			\Bitrix\Mobile\Feature\ImplementationSpecialistsFeature::class,
			\Bitrix\Mobile\Feature\PersonalAccountFeature::class,
			\Bitrix\Mobile\Feature\IntegratorFeature::class,
			\Bitrix\Mobile\Feature\PurchasesFeature::class,
			\Bitrix\Mobile\Feature\TariffMenuFeature::class,
			\Bitrix\Mobile\Feature\TariffMenuPurchaseCtaFeature::class,
			\Bitrix\Mobile\Feature\TariffMenuPurchaseCtaForceFeature::class,
			\Bitrix\Mobile\Feature\TariffMenuDemoModeFeature::class,
			\Bitrix\Mobile\Feature\UsersFloatingBannerFeature::class,
			\Bitrix\Mobile\Feature\BitrixAnswersFeature::class,
			\Bitrix\Mobile\Feature\BitrixAnswersSandboxFeature::class,
			\Bitrix\Mobile\Feature\MyCompanyFeature::class,
			\Bitrix\Mobile\Feature\QrLoginFeature::class,
		],
		'readonly' => true,
	],
	'services' => [
		'value' => [
			\Bitrix\Mobile\Internal\Dismiss\Storage\DismissStorage::class => [
				'constructor' => static fn() => new \Bitrix\Mobile\Internal\Dismiss\Storage\DismissStorage(
					\Bitrix\Main\Application::getConnection(),
					\Bitrix\Main\Application::getInstance()->getConnectionPool(),
				),
			],
			\Bitrix\Mobile\Internal\Dismiss\Command\DismissCommandHandler::class => [
				'className' => \Bitrix\Mobile\Internal\Dismiss\Command\DismissCommandHandler::class,
				'constructorParams' => static fn(): array => [
					\Bitrix\Main\DI\ServiceLocator::getInstance()
						->get(\Bitrix\Mobile\Internal\Dismiss\Storage\DismissStorage::class),
					(new \Bitrix\Main\Diag\LoggerFactory())->createById('mobile.dismiss') ?? new \Psr\Log\NullLogger(),
				],
			],
			\Bitrix\Mobile\Tab\Preset\Service\MobileMenuCacheInvalidator::class => [
				'constructor' => static fn() => new \Bitrix\Mobile\Tab\Preset\Service\MobileMenuCacheInvalidator(
					\Bitrix\Main\Application::getInstance()->getExceptionHandler(),
				),
			],
			\Bitrix\Mobile\BitrixGpt\Voice\AudioEngine::class => [
				'className' => \Bitrix\Mobile\BitrixGpt\Voice\AudioEngine::class,
				'constructorParams' => static function (): array {
					if (!\Bitrix\Main\Loader::includeModule('ai'))
					{
						return ['', ''];
					}

					$publicUrl = (string)\Bitrix\AI\Config::getValue('public_url');
					if ($publicUrl === '')
					{
						$publicUrl = \Bitrix\Main\Engine\UrlManager::getInstance()->getHostUrl();
					}

					return [
						$publicUrl,
						(string)\Bitrix\Main\Application::getInstance()->getLicense()->getRegion(),
					];
				},
			],
			\Bitrix\Mobile\BitrixGpt\Voice\Availability::class => [
				'className' => \Bitrix\Mobile\BitrixGpt\Voice\Availability::class,
				'constructorParams' => static function (): array {
					$serviceLocator = \Bitrix\Main\DI\ServiceLocator::getInstance();
					$martaReadiness = null;
					if (
						\Bitrix\Main\Loader::includeModule('aiassistant')
						&& class_exists(\Bitrix\AiAssistant\Service\MartaReadiness::class)
					)
					{
						$martaReadiness = $serviceLocator->get(\Bitrix\AiAssistant\Service\MartaReadiness::class);
					}

					return [
						$serviceLocator->get(\Bitrix\Mobile\BitrixGpt\Voice\AudioEngine::class),
						$martaReadiness,
					];
				},
			],
			\Bitrix\Mobile\BitrixGpt\Voice\Answer::class => [
				'className' => \Bitrix\Mobile\BitrixGpt\Voice\Answer::class,
				'constructorParams' => static function (): array {
					if (
						!\Bitrix\Main\Loader::includeModule('aiassistant')
						|| !\Bitrix\Main\Loader::includeModule('ai')
						|| !\Bitrix\Main\Loader::includeModule('im')
						|| !class_exists(\Bitrix\Im\V2\Public\Provider\Copilot\AnswerProvider::class)
					)
					{
						return [null, false, null];
					}

					$serviceLocator = \Bitrix\Main\DI\ServiceLocator::getInstance();

					return [
						$serviceLocator->get(\Bitrix\Ai\Services\MarkdownToBBCodeTranslationService::class),
						$serviceLocator->get(\Bitrix\AiAssistant\Config\Feature::class)->isNativeMarkdownEnabled(),
						$serviceLocator->get(\Bitrix\Im\V2\Public\Provider\Copilot\AnswerProvider::class),
					];
				},
			],
			\Bitrix\Mobile\BitrixGpt\Voice\Transcription::class => [
				'className' => \Bitrix\Mobile\BitrixGpt\Voice\Transcription::class,
				'constructorParams' => static function (): array {
					return [
						\Bitrix\Main\DI\ServiceLocator::getInstance()
							->get(\Bitrix\Mobile\BitrixGpt\Voice\AudioEngine::class),
					];
				},
			],
		],
		'readonly' => true,
	],
	'messenger' => [
		'value' => [
			'queues' => [
				'mobile.menu_cache_invalidation' => [
					'handler' => \Bitrix\Mobile\Tab\Preset\Integration\Messenger\MenuCacheInvalidationReceiver::class,
					'limit' => 1,
					'total_processing_limit' => 1,
					'retry_strategy' => [
						'max_retries' => 3,
						'delay' => 5,
						'multiplier' => 2,
						'max_delay' => 60,
					],
				],
			],
		],
		'readonly' => true,
	],
];
