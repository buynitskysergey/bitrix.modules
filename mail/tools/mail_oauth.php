<?php

use Bitrix\Mail;
use Bitrix\Mail\Helper\OAuth;
use Bitrix\Main\Loader;

define('NOT_CHECK_PERMISSIONS', true);

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

if (Loader::includeModule('mail') && Loader::includeModule('socialservices'))
{
	$rawState = (string)($_REQUEST['state'] ?? '');
	$state = \Bitrix\Socialservices\OAuth\StateService::getInstance()->getPayload($rawState);

	$helper =
		is_array($state) && isset($state['service'])
			? Mail\Helper\OAuth::getInstance($state['service'])
			: null
	;

	if ($helper)
	{
		$error = (string)($_REQUEST['error'] ?? '');

		if ($error !== '')
		{
			// The provider refused instead of returning a code: without this branch
			// the response body stays empty and the user sees a blank window.
			$helper->renderError(
				$state,
				$error,
				(string)($_REQUEST['error_description'] ?? '')
			);
		}
		elseif (isset($_SESSION["MOBILE_OAUTH"]) && $_SESSION["MOBILE_OAUTH"])
		{
			$helper->handleResponse($state, OAuth::MOBILE_TYPE);
		}
		else
		{
			$helper->handleResponse($state);
		}
	}
}

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php');
