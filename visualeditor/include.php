<?php

\Bitrix\Main\Loader::registerAutoLoadClasses('visualeditor', [
	'Bitrix\\VisualEditor\\Public\\Adapter\\EditorAdapterInterface' => 'lib/Public/Adapter/EditorAdapterInterface.php',
	'Bitrix\\VisualEditor\\Public\\Adapter\\AdapterRegistry' => 'lib/Public/Adapter/AdapterRegistry.php',
	'Bitrix\\VisualEditor\\Internal\\UseCase\\ListFiles' => 'lib/Internal/UseCase/ListFiles.php',
	'Bitrix\\VisualEditor\\Internal\\UseCase\\ReadFile' => 'lib/Internal/UseCase/ReadFile.php',
	'Bitrix\\VisualEditor\\Infrastructure\\Controller\\Editor' => 'lib/Infrastructure/Controller/Editor.php',
	'Bitrix\\VisualEditor\\Internal\\Service\\Editor\\ChangeSetValidator' =>
		'lib/Internal/Service/Editor/ChangeSetValidator.php',
	'Bitrix\\VisualEditor\\Internal\\Service\\Editor\\ChangeSetApplier' =>
		'lib/Internal/Service/Editor/ChangeSetApplier.php',
	'Bitrix\\VisualEditor\\Internal\\Service\\Editor\\ChangeSetProcessor' =>
		'lib/Internal/Service/Editor/ChangeSetProcessor.php',
	'Bitrix\\VisualEditor\\Internal\\UseCase\\SaveFile' => 'lib/Internal/UseCase/SaveFile.php',
]);
