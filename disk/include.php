<?php

use Bitrix\Disk\Document\OnlyOffice\Bitrix24Scenario;
use Bitrix\Disk\Document\OnlyOffice\ExporterBitrix24Scenario;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Repository\DockedUpload\DockedUploadItemRepository;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\BizProcParameterDescriptorProvider;
use Bitrix\Disk\Internal\Service\DockedUpload\BizProcParametersValidator;
use Bitrix\Disk\Internal\Service\DockedUpload\CommitReconciler;
use Bitrix\Disk\Internal\Service\DockedUpload\DisplayStatusResolver;
use Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderResolverService;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderTreeBuilder;
use Bitrix\Disk\Internal\Service\DockedUpload\NoopPostCommitHook;
use Bitrix\Disk\Internal\Service\DockedUpload\OwnershipService;
use Bitrix\Disk\Internal\Service\DockedUpload\QueueEventNotifier;
use Bitrix\Disk\Internal\Service\DockedUpload\QuotaScenario;
use Bitrix\Disk\Internal\Service\Document\OfficeEditorAvailability;
use Bitrix\Disk\Internal\Service\Document\OfficeEngineRecognizer;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Public\Provider\DockedUpload\QueueRestoreProvider;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\IncreaseLimitRequestMessageResolver;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\IncreaseLimitRequestMessageResolverInterface;
use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\NullIncreaseLimitRequestMessageResolver;
use Bitrix\Disk\QuickAccess;
use Bitrix\Disk\QuickAccess\Config\ConfigInterface;
use Bitrix\Disk\QuickAccess\FileInfo\ProviderFactory;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

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
		"bitrix\\disk\\controller\\audit" => "lib/controller/Audit.php",
		"bitrix\\disk\\controller\\auditsettings" => "lib/controller/AuditSettings.php",
		"bitrix\\disk\\controller\\filepicker" => "lib/controller/FilePicker.php",
		"bitrix\\disk\\controller\\destination" => "lib/controller/Destination.php",
	)
);

// Dev/QA controllers (e2e provisioning among them) live under dev/, which is not part of a
// shipped portal, so they exist only in a developer checkout. Registered the way ui, imbot and
// statistic register theirs: the whole dev/ under Controller\Dev — Controller, not Dev, because
// the action resolver only routes to classes under the module's controller namespaces from
// .settings.php. Each controller under it guards itself in processBeforeAction.
if (is_dir(__DIR__ . '/dev'))
{
	Loader::registerNamespace('Bitrix\\Disk\\Controller\\Dev', __DIR__ . '/dev');
	Loader::registerNamespace('Bitrix\\Disk\\Controller\\Dev\\E2e', __DIR__ . '/dev/e2e');
}


CJSCore::RegisterExt('disk', array(
	'js' => '/bitrix/js/disk/c_disk.js',
	'css' => '/bitrix/js/disk/css/disk.css',
	'lang' => BX_ROOT.'/modules/disk/lang/'.LANGUAGE_ID.'/js_disk.php',
	'rel' => array('core', 'popup', 'ajax', 'fx', 'dd', 'ui.notification', 'ui.design-tokens', 'ui.fonts.opensans'),
	'oninit' => function() {

		$bitrix24Scenario = new Bitrix24Scenario();
		$exporterBitrix24Scenario = new ExporterBitrix24Scenario($bitrix24Scenario);
		$onlyOfficeEnabled = \Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler::isEnabled();
		$officeEditorAvailable = OfficeEditorAvailability::isAvailable();
		$officeEditorCodes = OfficeEngineRecognizer::listOfficeEditorCodes();
		// The promo popup depends on this extension: as 'rel' it is loaded after this extension is marked loaded,
		// while a load from inside oninit would run oninit once more.
		$relatedExtensions = $onlyOfficeEnabled ? ['disk.onlyoffice-promo-popup'] : [];

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
					BX.message["disk_office_editor_available"] = ' . Json::encode($officeEditorAvailable) . ';
					BX.message["disk_office_editor_codes"] = ' . Json::encode($officeEditorCodes) . ';
					BX.message["disk_revision_api"] = ' . (int)\Bitrix\Disk\Configuration::getRevisionApi() . ';
					BX.message["disk_document_service"] = "' . (string)\Bitrix\Disk\UserConfiguration::getDocumentServiceCode() . '"
					' . ($onlyOfficeEnabled ? $exporterBitrix24Scenario->exportToBxMessages() : '') . '
				</script>
			');

			return [
				'rel' => $relatedExtensions,
			];
		}
		else
		{
			$messages = [
				'disk_restriction' => false,
				'disk_onlyoffice_available' => $onlyOfficeEnabled,
				'disk_office_editor_available' => $officeEditorAvailable,
				'disk_office_editor_codes' => $officeEditorCodes,
				'disk_revision_api' => (int)\Bitrix\Disk\Configuration::getRevisionApi(),
				'disk_document_service' => (string)\Bitrix\Disk\UserConfiguration::getDocumentServiceCode(),
			];

			$scenarioMessages = $onlyOfficeEnabled ? $exporterBitrix24Scenario->exportToArray() : [];

			return [
				'lang_additional' => array_merge($messages, $scenarioMessages),
				'rel' => $relatedExtensions,
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
	'rel' => array('disk', 'ui.a11y', 'disk.dialog-markup'),
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
	'rel' => ['core', 'disk', 'helper', 'intranet.desktop-download', 'ui.a11y', 'disk.dialog-markup'],
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

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.folderResolver', [
	'constructor' => static function () {
		return new FolderResolverService(Driver::getInstance());
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.uploader', [
	'constructor' => static function () {
		return new \Bitrix\UI\FileUploader\Uploader(
			new \Bitrix\Disk\Controller\DockedUploader\Transport([])
		);
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.displayStatusResolver', [
	'constructor' => static function () {
		return new DisplayStatusResolver(new QuotaScenario());
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.bizProcParameterDescriptorProvider', [
	'constructor' => static function () {
		return new BizProcParameterDescriptorProvider();
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.bizProcParametersValidator', [
	'constructor' => static function () {
		return new BizProcParametersValidator(
			ServiceLocator::getInstance()->get('disk.dockedUpload.bizProcParameterDescriptorProvider'),
		);
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.folderTreeBuilder', [
	'constructor' => static function () {
		return new FolderTreeBuilder(
			ServiceLocator::getInstance()->get('disk.dockedUpload.folderResolver'),
			new DockedUploadItemRepository(),
		);
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.queueRestoreProvider', [
	'constructor' => static function () {
		$serviceLocator = ServiceLocator::getInstance();

		return new QueueRestoreProvider(
			$serviceLocator->get(DockedUploadQueueRepositoryInterface::class),
			new DockedUploadItemRepository(),
			$serviceLocator->get('disk.dockedUpload.displayStatusResolver'),
			new CommitReconciler(),
			$serviceLocator->get('disk.dockedUpload.fileCommitService'),
			$serviceLocator->get('disk.dockedUpload.ownershipService'),
			LoggerFactory::create(feature: 'docked upload'),
		);
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.fileCommitService', [
	'constructor' => static function () {
		$serviceLocator = ServiceLocator::getInstance();

		return new FileCommitService(
			new DockedUploadItemRepository(),
			$serviceLocator->get(DockedUploadQueueRepositoryInterface::class),
			$serviceLocator->get('disk.dockedUpload.folderResolver'),
			new CommitReconciler(),
			new QuotaScenario(),
			new NoopPostCommitHook(),
			$serviceLocator->get('disk.dockedUpload.uploader'),
			LoggerFactory::create(feature: 'docked upload'),
		);
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.ownershipService', [
	'constructor' => static function () {
		$serviceLocator = ServiceLocator::getInstance();

		return new OwnershipService(
			new DockedUploadItemRepository(),
			$serviceLocator->get(DockedUploadQueueRepositoryInterface::class),
			LoggerFactory::create(feature: 'docked upload'),
		);
	},
]);

ServiceLocator::getInstance()->addInstanceLazy('disk.dockedUpload.queueEventNotifier', [
	'constructor' => static function () {
		return new QueueEventNotifier();
	},
]);
