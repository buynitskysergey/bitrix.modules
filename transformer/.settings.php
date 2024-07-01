<?php
return [
	'services' => [
		'value' => [
			'transformer.http.controllerResolver' => [
				'constructor' => static function () {
					$feature = \Bitrix\Transformer\Integration\Baas::getDedicatedControllerFeature();

					return new \Bitrix\Transformer\Http\ControllerResolver($feature);
				},
			],

			'transformer.integration.analytics.registrar' => [
				'constructor' => static function () {
					$feature = \Bitrix\Transformer\Integration\Baas::getDedicatedControllerFeature();

					return new \Bitrix\Transformer\Integration\Analytics\Registrar($feature);
				},
			],
		],
	],
];
