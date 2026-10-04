<?php

use Bitrix\AI\Engine;
use Bitrix\AI\Integration\Fileman\HtmlEditorHandler;
use Bitrix\Main\Application;
use Bitrix\Main\EventResult;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Copyright;

$region = strtolower((string)(Application::getInstance()->getLicense()->getRegion() ?? 'en'));
if ($region === 'cn')
{
	return false;
}

Engine::triggerEngineAddedEvent();
Engine::addEngine(\Bitrix\AI\Engine\Enum\Category::CLASSIFY, \Bitrix\AI\Engine\Triton\Classify::class);

EventManager::getInstance()->addEventHandler(
	'fileman',
	'HtmlEditor:onBeforeBuild',
	[HtmlEditorHandler::class, 'onBeforeBuild'],
);

include(__DIR__ . '/prompt_updater.php');

$documentRoot = Loader::getDocumentRoot();
if (is_dir($documentRoot . '/bitrix/modules/ai/dev/'))
{
	// developer mode
	Loader::registerNamespace('Bitrix\AI\Dev',  $documentRoot . '/bitrix/modules/ai/dev');
}

Loader::registerAutoLoadClasses(null, [
	'Parsedown' => '/bitrix/modules/ai/vendor/erusev/parsedown/Parsedown.php'
]);
