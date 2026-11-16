<?php
return [
	"controllers" => [
		"value" => [
			"defaultNamespace" => "\\Bitrix\\Sender\\UI\\Controller"
		],
		'restIntegration' => [
			'enabled' => false,
		],
		"readonly" => true,
	],
	'services' => [
		'value' => [
			\Bitrix\Sender\Dispatch\DurationFormatter::class => [
				'className' => \Bitrix\Sender\Dispatch\DurationFormatter::class,
			],
		],
		'readonly' => true,
	],
	"yandex.integration.widget" => [
		"value" => [
			"partnerId" => 2706070
		]
	]
];
