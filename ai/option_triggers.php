<?php

use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

// Included from Option::loadTriggers() on the first Option::set() of this module, so the file
// shares scope with that method and must not declare variables of its own.
EventManager::getInstance()->addEventHandler(
	'main',
	'OnAfterSetOption',
	// VERSION 2 handler: the callable receives exactly one argument, the event object.
	static function (Event $event): void {
		// The order of the checks carries the protection: the module check cuts recursion through
		// the marker namespace, and the value check keeps a semiloaded module out of the loader.
		try
		{
			if ($event->getParameter('moduleId') !== 'ai')
			{
				return;
			}

			if ($event->getParameter('value') !== 'Y')
			{
				return;
			}

			if ((string)$event->getParameter('siteId') !== '')
			{
				return;
			}

			// Before the load, and not after: a negative load result is cached for the whole
			// process and would break includeModule('ai') for the rest of the request.
			if (!ModuleManager::isModuleInstalled('ai'))
			{
				return;
			}

			// Module classes may be referenced only below this line: until the module is loaded
			// its namespace is not registered.
			if (!Loader::includeModule('ai'))
			{
				return;
			}

			(new \Bitrix\AI\Analytics\FeatureReleaseTracker(
				'ai',
				\Bitrix\AI\Config\FeatureFlagOptions::getExcludedOptions(),
			))->handleOptionWrite((string)$event->getParameter('name'));
		}
		catch (\Throwable)
		{
		}
	},
);
