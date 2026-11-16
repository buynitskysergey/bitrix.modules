<?php

require_once __DIR__ . '/autoload.php';

if (file_exists(__DIR__ . '/dev/Env/Include.php'))
{
	require_once __DIR__ . '/dev/Env/Include.php';
}

// developer mode. Only dev/e2e is mapped: the action resolver autoloads any file under a
// registered controller namespace by the action name, and the rest of dev/ holds standalone scripts.
if (is_dir(__DIR__ . '/dev/e2e'))
{
	\Bitrix\Main\Loader::registerNamespace('Bitrix\HumanResources\Controller\Dev\E2e', __DIR__ . '/dev/e2e');
}
