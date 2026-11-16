<?php
$call_default_option = [
	'call_server_enabled' => true,
	'call_server_max_users' => 100,
	'call_server_large_room' => 1000,
	'call_server_url' => '',
	'call_balancer_url' => '',
	'turn_server_self' => 'N',
	'turn_server' => (\Bitrix\Main\Application::getInstance()->getLicense()->isCis() ? 'turn.bitrix24.tech' : 'turn.calls.bitrix24.com'),
	'turn_server_login' => 'bitrix',
	'turn_server_password' => 'bitrix',
	'turn_server_max_users' => 4,
	'call_log_service' => '',
	'call_log_secret' => '',
	'call_ai_enabled' => true,
	'call_invite_period' => 30000,
	'call_sync_invite_period' => 300000,
	'call_reactions_enabled' => false,
	'call_reactions_send_throttle_ms' => 600,
	'call_reactions_float_duration_ms' => 4000,
	'call_reactions_max_on_screen' => 20,
	'call_reactions_mobile_max_on_screen' => 6,
	'call_reactions_max_queue' => 100,
	'call_web_background_enabled' => 'N',
	'call_web_background_min_cores' => 4,
	'call_web_background_min_memory_gb' => 4,
	'call_web_background_renderer_denylist' => 'swiftshader,llvmpipe,basic render',
	'call_web_background_probe_frames' => 25,
	'call_web_background_probe_threshold_ms' => 40,
];

if (file_exists($_SERVER["DOCUMENT_ROOT"]."/bitrix/php_interface/call_options.php"))
{
	$additionalOptions = include($_SERVER["DOCUMENT_ROOT"]."/bitrix/php_interface/call_options.php");
	if (is_array($additionalOptions))
	{
		$call_default_option = array_merge($call_default_option, $additionalOptions);
	}
}
