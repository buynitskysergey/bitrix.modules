<?php

return [
	'feature-flags' => [
		'value' => [
			'defaultNamespace' => '\\Bitrix\\Tasks\\Feature',
		],
		'readonly' => true,
	],
	'controllers' => require __DIR__ . '/config/controllers.php',
	'ui.uploader' => require __DIR__ . '/config/uploader.php',
	'ui.entity-selector' => require __DIR__ . '/config/entity-selector.php',
	'services' => require __DIR__ . '/config/services.php',
	'messenger' => require __DIR__ . '/config/messenger.php',
	'consistency' => require __DIR__ . '/config/consistency.php',
	'events' => require __DIR__ . '/config/events.php',
	'rest' => require __DIR__ . '/config/rest.php',
	'aiassistant.marta' => require __DIR__ . '/config/marta.php',
];
