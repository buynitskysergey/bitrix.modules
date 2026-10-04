<?php

use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Mobile\AppTabs\Calendar;
use Bitrix\Mobile\AppTabs\CatalogStore;
use Bitrix\Mobile\AppTabs\Chat;
use Bitrix\Mobile\AppTabs\Crm;
use Bitrix\Mobile\AppTabs\Mail;
use Bitrix\Mobile\AppTabs\Marketplace;
use Bitrix\Mobile\AppTabs\CrmCustomSectionFactory;
use Bitrix\Mobile\AppTabs\Disk;
use Bitrix\Mobile\AppTabs\Menu;
use Bitrix\Mobile\AppTabs\MenuNew;
use Bitrix\Mobile\AppTabs\Projects;
use Bitrix\Mobile\AppTabs\Stream;
use Bitrix\Mobile\AppTabs\Task;
use Bitrix\Mobile\AppTabs\Terminal;
use Bitrix\Mobile\AppTabs\CallList;
use Bitrix\Mobile\AppTabs\Sync;
use Bitrix\Mobile\Config\Feature;
use Bitrix\MobileApp\Mobile;
use Bitrix\Mobile\Feature\MenuFeature;

Mobile::Init();

$isDiskAvailable = Loader::includeModule('diskmobile');
$isSyncPresetEnabled = Loader::includeModule('call') && \Bitrix\Call\Settings::isSyncPresetEnabled();

$config = [
	'tabs' => [
		['code' => 'chat', 'class' => Chat::class],
		['code' => 'stream', 'class' => Stream::class],
		['code' => 'task', 'class' => Task::class],
		['code' => 'call_list', 'class' => CallList::class],
		['code' => 'sync', 'class' => Sync::class],
		['code' => 'menu', 'class' => Feature::isEnabled(MenuFeature::class) ? MenuNew::class : Menu::class],
		['code' => 'crm', 'class' => Crm::class],
		['code' => 'marketplace', 'class' => Marketplace::class],
		['code' => 'terminal', 'class' => Terminal::class],
		['code' => 'catalog_store', 'class' => CatalogStore::class],
		['code' => 'projects', 'class' => Projects::class],
		['code' => 'calendar', 'class' => Calendar::class],
		['code' => 'crmCustomSectionFactory', 'class' => CrmCustomSectionFactory::class],
		['code' => 'disk', 'class' => Disk::class],
		['code' => 'mail' , 'class' => Mail::class],
	],
	'required' => [
		'chat' => 100,
		'menu' => 1000,
	],
	'optional' => [
		'crm',
		'menu' => 2000,
	],
	'unchangeable' => [
		'menu' => 1000,
	],
	'presetRequiredTabs' => [
		'task' => ['task'],
		'stream' => ['stream'],
		'crm' => ['crm'],
		'collaboration' => ['chat'],
		'terminal' => ['terminal'],
		'bizproc' => ['bizproc'],
		'sign' => ['sign'],
	],
	'defaultUserPreset' => array_filter([
		'chat' => 100,
		'sync' => ($isSyncPresetEnabled ? 200 : false),
		'call_list' => ($isSyncPresetEnabled ? false : 200),
		'task' => 300,
		'menu' => 1000,
	]),
	'defaultCollaberPreset' => [
		'chat' => 100,
		'disk' => 200,
		'calendar' => 300,
		'task' => 400,
		'menu' => 1000,
	],
	'defaultGuestPreset' => [
		'chat' => 100,
		'call_list' => 200,
		'menu' => 1000,
	],
	/** @see Bitrix\Mobile\Tab\Manager::migratePresetsByVersion */
	'presetLegacy' => [
		'currentVersion' => 2, // up version if you change legacy preset
		'task' => [
			'task' => 100,
			'chat' => 200,
			'stream' => 300,
			'calendar' => 400,
			'menu' => 1000,
		],
		'collaboration' => [
			'chat' => 100,
			'sync' => 120,
			'task' => 200,
			'calendar' => 250,
			'menu' => 1000,
		],
		'bizproc' => [
			'chat' => 100,
			'bizproc' => 200,
			'stream' => 300,
			'task' => 400,
			'menu' => 1000,
		]
	],
	'presets' => [ // if you change presets you must add old version to legacy preset
		'task' => array_filter([
			'task' => 100,
			'chat' => 200,
			'sync' => ($isSyncPresetEnabled ? 250 : false),
			'call_list' => ($isSyncPresetEnabled ? false : 250),
			'mail' => 300,
			'menu' => 1000,
		]),
		'stream' => array_filter([
			'stream' => 100,
			'chat' => 150,
			'task' => 200,
			'crm' => (!$isDiskAvailable ? 250 : false),
			'disk' => ($isDiskAvailable ? 250 : false),
			'menu' => 1000,
		], 'intval'),
		'crm' => [
			'crm' => 100,
			'chat' => 200,
			'task' => 300,
			'calendar' => 400,
			'menu' => 1000,
		],
		'collaboration' => array_filter([
			'chat' => 100,
			'sync' => ($isSyncPresetEnabled ? 120 : false),
			'call_list' => ($isSyncPresetEnabled ? false : 120),
			'task' => 200,
			'calendar' => 250,
			'menu' => 1000,
		]),
		'terminal' => [
			'terminal' => 100,
			'chat' => 120,
			'menu' => 1000,
		],
	],
	'presetsOptions' => [
		'manual' => [
			'sort' => 50,
			'messageCode' => 'TAB_PRESET_NAME_MANUAL_V2',
		],
		'collaboration' => [
			'sort' => 100,
			'messageCode' => 'TAB_PRESET_NAME_COLLABORATION',
		],
		'task' => [
			'sort' => 200,
			'messageCode' => 'TAB_PRESET_NAME_TASK_MSGVER_1',
		],
		'crm' => [
			'sort' => 300,
			'messageCode' => 'TAB_PRESET_NAME_CRM_V2',
		],
		'bizproc' => [
			'sort' => 400,
			'messageCode' => 'TAB_PRESET_NAME_BIZPROC',
		],
		'sign' => [
			'sort' => 500,
			'messageCode' => 'TAB_PRESET_NAME_SIGN',
		],
		'stream' => [
			'sort' => 600,
			'messageCode' => 'TAB_PRESET_NAME_STREAM_V2',
		],
		'terminal' => [
			'sort' => 700,
			'messageCode' => 'TAB_PRESET_NAME_TERMINAL',
		],
	],
];

if (Loader::includeModule('bizproc'))
{
	$config['presets']['bizproc'] = array_filter([
		'chat' => 100,
		'bizproc' => 150,
		'calendar' =>  250,
		'task' => 250,
		'menu' => 1000,
	]);
}

if (
	Loader::includeModule('sign')
	&& !empty(EventManager::getInstance()->findEventHandlers('mobile', 'onBeforeTabsGet', ['signmobile']))
) {
	$config['presets']['sign'] = [
		'chat' => 100,
		'sign' => 150,
		'task' => 200,
		'stream' => 250,
		'menu' => 1000,
	];
}

foreach (EventManager::getInstance()->findEventHandlers('mobile', 'onBeforeTabsGet') as $event)
{
	$tabs = ExecuteModuleEventEx($event);
	if (is_array($tabs))
	{
		$config['tabs'] = array_merge($config['tabs'], $tabs);
	}
}

return $config;
