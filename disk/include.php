<?php

use Bitrix\Disk\Document\OnlyOffice\Bitrix24Scenario;
use Bitrix\Disk\Document\OnlyOffice\ExporterBitrix24Scenario;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\IncreaseLimitRequestMessageResolver;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\IncreaseLimitRequestMessageResolverInterface;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\NullIncreaseLimitRequestMessageResolver;
use Bitrix\Disk\QuickAccess;
use Bitrix\Disk\QuickAccess\Config\ConfigInterface;
use Bitrix\Disk\QuickAccess\FileInfo\ProviderFactory;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

Loader::registerAutoLoadClasses(
	"disk",
	array(
		"disk" => "install/index.php",
		"bitrix\\disk\\uf\\crmconnector" => "lib/uf/crmconnector.php",
		"bitrix\\disk\\uf\\crmdealconnector" => "lib/uf/crmconnector.php",
		"bitrix\\disk\\uf\\crmleadconnector" => "lib/uf/crmconnector.php",
		"bitrix\\disk\\uf\\crmcompanyconnector" => "lib/uf/crmconnector.php",
		"bitrix\\disk\\uf\\crmcontactconnector" => "lib/uf/crmconnector.php",
		"bitrix\\disk\\uf\\crmmessageconnector" => "lib/uf/crmconnector.php",
		"bitrix\\disk\\uf\\crmmessagecommentconnector" => "lib/uf/crmconnector.php",
		"bitrix\\disk\\configuration" => "lib/configuration.php",
		"bitrix\\disk\\userconfiguration" => "lib/configuration.php",
		"bitrix\\disk\\folder" => "lib/folder.php",
		"bitrix\\disk\\specificfolder" => "lib/folder.php",
		"bitrix\\disk\\controller\\filepicker" => "lib/Controller/FilePicker.php",
	)
);


CJSCore::RegisterExt('disk', array(
	'js' => '/bitrix/js/disk/c_disk.js',
	'css' => '/bitrix/js/disk/css/disk.css',
	'lang' => BX_ROOT.'/modules/disk/lang/'.LANGUAGE_ID.'/js_disk.php',
	'rel' => array('core', 'popup', 'ajax', 'fx', 'dd', 'ui.notification', 'ui.design-tokens', 'ui.fonts.opensans'),
	'oninit' => function() {

		$bitrix24Scenario = new Bitrix24Scenario();
		$exporterBitrix24Scenario = new ExporterBitrix24Scenario($bitrix24Scenario);
		$onlyOfficeEnabled = \Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler::isEnabled();

		if ($onlyOfficeEnabled)
		{
			Extension::load('disk.onlyoffice-promo-popup');
		}

		$isCompositeMode = defined("USE_HTML_STATIC_CACHE") && USE_HTML_STATIC_CACHE === true;

		if($isCompositeMode)
		{
			// It's a hack. The package "disk" can be included in static area and pasted in <head>.
			// It means that every page has this BX.messages in composite cache. But we have user's depended options in BX.messages.
			// And in this case we'll rewrite composite cache and have invalid data in composite cache.
			// So in this way we have to insert BX.messages in dynamic area by viewContent placeholders.
			global $APPLICATION;
			$APPLICATION->AddViewContent("inline-scripts", '
				<script>
					BX.message["disk_restriction"] = false;
					BX.message["disk_onlyoffice_available"] = ' . (int)$onlyOfficeEnabled . ';
					BX.message["disk_revision_api"] = ' . (int)\Bitrix\Disk\Configuration::getRevisionApi() . ';
					BX.message["disk_document_service"] = "' . (string)\Bitrix\Disk\UserConfiguration::getDocumentServiceCode() . '"
					' . ($onlyOfficeEnabled ? $exporterBitrix24Scenario->exportToBxMessages() : '') . '
				</script>
			');
		}
		else
		{
			$messages = [
				'disk_restriction' => false,
				'disk_onlyoffice_available' => $onlyOfficeEnabled,
				'disk_revision_api' => (int)\Bitrix\Disk\Configuration::getRevisionApi(),
				'disk_document_service' => (string)\Bitrix\Disk\UserConfiguration::getDocumentServiceCode(),
			];

			$scenarioMessages = $onlyOfficeEnabled ? $exporterBitrix24Scenario->exportToArray() : [];

			return [
				'lang_additional' => array_merge($messages, $scenarioMessages),
			];
		}
	},
));

CJSCore::RegisterExt('file_dialog', array(
	'js' => '/bitrix/js/disk/file_dialog.js',
	'css' => '/bitrix/js/disk/css/file_dialog.css',
	'lang' => '/bitrix/modules/disk/lang/'.LANGUAGE_ID.'/install/js/file_dialog.php',
	'rel' => array('core', 'popup', 'ajax', 'disk', 'ui.design-tokens'),
));

CJSCore::RegisterExt('disk_desktop', array(
	'js' => '/bitrix/js/disk/disk_desktop.js',
	'lang' => '/bitrix/modules/disk/lang/'.LANGUAGE_ID.'/install/js/disk_desktop.php',
	'rel' => array('core',),
));

CJSCore::RegisterExt('disk_tabs', array(
	'js' => '/bitrix/js/disk/tabs.js',
	'css' => '/bitrix/js/disk/css/tabs.css',
	'rel' => array('core', 'disk',),
));

CJSCore::RegisterExt('disk_queue', array(
	'js' => '/bitrix/js/disk/queue.js',
	'rel' => array('core', 'disk',),
));

CJSCore::RegisterExt('disk_page', array(
	'js' => '/bitrix/js/disk/page.js',
	'rel' => array('disk',),
));

CJSCore::RegisterExt('disk_folder_tree', array(
	'js' => '/bitrix/js/disk/tree.js',
	'rel' => array('disk',),
));

CJSCore::RegisterExt('disk_external_loader', [
	'js' => '/bitrix/js/disk/external_loader.js',
	'rel' => [
		'core',
		'disk',
		'disk_queue',
		// Some components add disk.uf.file/templates/.default/script.js directly
		// script.js contains BX.Disk.UF.add - compatibility API
		'ui.uploader.core',
	],
]);

CJSCore::RegisterExt('disk_information_popups', [
	'js' => '/bitrix/js/disk/information_popups.js',
	'lang' => '/bitrix/modules/disk/lang/'.LANGUAGE_ID.'/install/js/information_popups.php',
	'rel' => ['core', 'disk', 'helper', 'intranet.desktop-download'],
]);

\Bitrix\Disk\Internals\Engine\Binder::registerDefaultAutoWirings();


ServiceLocator::getInstance()->addInstanceLazy('disk.userQuickAccessTokenManager', [
	'constructor' => static function () {
		$quickAccessConfig = ServiceLocator::getInstance()->get(ConfigInterface::class);

		return new QuickAccess\UserQuickAccessTokenManager(
			Bitrix\Main\Context::getCurrent()?->getRequest(),
			Bitrix\Main\Context::getCurrent()?->getResponse(),
			$quickAccessConfig->getKey(),
		);
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.scopeTokenService', [
	'constructor' => static function () {
		$quickAccessConfig = ServiceLocator::getInstance()->get(ConfigInterface::class);
		$signerKey = $quickAccessConfig->getKey();

		$storageFactory = QuickAccess\Storage\StorageFactory::create($quickAccessConfig->getTokenStorage());
		$fileInfoProviderFactory = ServiceLocator::getInstance()->get(ProviderFactory::class);
		$userTokenManager = ServiceLocator::getInstance()->get('disk.userQuickAccessTokenManager');
		$quickAccessReadinessChecker = new QuickAccess\QuickAccessReadinessChecker($signerKey);

		return new QuickAccess\ScopeTokenService(
			$storageFactory,
			$fileInfoProviderFactory,
			$signerKey,
			$userTokenManager,
			$quickAccessReadinessChecker,
		);
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.fileDataParameterService', [
	'constructor' => static function () {
		$quickAccessConfig = ServiceLocator::getInstance()->get(ConfigInterface::class);
		$signerKey = $quickAccessConfig->getKey();
		$fileInfoProviderFactory = ServiceLocator::getInstance()->get(ProviderFactory::class);
		$userTokenManager = ServiceLocator::getInstance()->get('disk.userQuickAccessTokenManager');
		$quickAccessReadinessChecker = new QuickAccess\QuickAccessReadinessChecker($signerKey);

		return new QuickAccess\FileDataParameterService(
			$signerKey,
			$fileInfoProviderFactory,
			$userTokenManager,
			$quickAccessReadinessChecker,
		);
	},
]);

ServiceLocator::getInstance()->addInstanceLazy(IncreaseLimitRequestMessageResolverInterface::class, [
	'constructor' => static function () {
		if (!Loader::includeModule('humanresources') || !Loader::includeModule('im'))
		{
			return ServiceLocator::getInstance()->get(NullIncreaseLimitRequestMessageResolver::class);
		}

		return ServiceLocator::getInstance()->get(IncreaseLimitRequestMessageResolver::class);
	},
]);
