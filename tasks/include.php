<?php

use Bitrix\Main\Localization\Loc;

IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/tasks/lang.php');

Loc::loadMessages(__FILE__);

$moduleRoot = $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks';

require_once __DIR__ . '/autoload.php';
require_once $moduleRoot . '/tools.php';

require_once __DIR__ . '/include/internal_events.php';
require_once $moduleRoot . '/include/asset.php';
require_once $moduleRoot . '/include/migrate_recent_tasks.php';
