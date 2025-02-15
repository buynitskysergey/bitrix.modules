<?
if (defined("STOP_WEBDAV") && STOP_WEBDAV)
	return;

$application = \Bitrix\Main\Application::getInstance();
if($application->hasCurrentRoute())
{
	$route = $application->getCurrentRoute();
	//it means that route has specific methods, not set just "any"
	if ($route->getOptions()->getMethods())
	{
		return;
	}
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS')
{
	if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']) && preg_match("/Crm-Webform-Cors/i", $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
	{
		return;
	}
}

if (!function_exists("__webdav_is_dav_headers"))
{
	function __webdav_is_dav_headers()
	{
		$aDavHeaders = array(
			"DAV",
			"IF",
			"DEPTH",
			"OVERWRITE",
			"DESTINATION",
			"LOCK_TOKEN",
			"TIMEOUT",
			"STATUS_URI");
		foreach ($aDavHeaders as $header)
		{
			if (array_key_exists("HTTP_".$header, $_SERVER))
			{
				return true;
			}
		}
		$aDavMethods = array(
			//"HEAD", // not webdav
			"OPTIONS", // not webdav
			"PUT", // not webdav
			"PROPFIND",
			"PROPPATCH",
			"MKCOL",
			"COPY",
			"MOVE",
			"LOCK",
			"UNLOCK");
		foreach ($aDavMethods as $method)
		{
			if ($_SERVER["REQUEST_METHOD"] == $method)
			{
				return true;
			}
		}
		if (mb_strpos($_SERVER['HTTP_USER_AGENT'], "Microsoft Office") !== false &&
			mb_strpos($_SERVER['HTTP_USER_AGENT'], "Outlook") === false
		)
		{
			return true;
		}
		return false;
	}
}
$bNeedInclude = true;
if ($_SERVER["REQUEST_METHOD"] == "HEAD")
{
	$res = mb_strtolower($_SERVER["HTTP_USER_AGENT"]);
	if (mb_strpos($res, "microsoft") === false &&
		$_SERVER["REAL_FILE_PATH"] == '' && mb_substr($_SERVER['REQUEST_URI'], -1, 1) == '/')
	{
		$bNeedInclude = false;
		$res = CUrlRewriter::GetList(Array("QUERY" => $_SERVER['REQUEST_URI']));
		foreach($res as $res_detail)
		{
			if (mb_strpos($res_detail["ID"], "webdav") !== false || mb_strpos($res_detail["ID"], "socialnetwork") !== false)
			{
				$bNeedInclude = true;
				break;
			}
		}
	}
/*
	$arUserAgents = array(
		// XP
		"Microsoft Data Access Internet Publishing Provider DAV",
		"Microsoft Office Existence Discovery",
		// W7
		"Microsoft Office Protocol Discovery",
		"Microsoft Office Existence Discovery",
		"Microsoft-WebDAV-MiniRedir/6.1.7600",
		"Microsoft Office Core Storage Infrastructure/1.0",

		);
*/
}

if (__webdav_is_dav_headers() && $bNeedInclude)
{
    if(CModule::includeModule('ldap') && CLdapUtil::isBitrixVMAuthSupported())
    {
        CLdapUtil::bitrixVMAuthorize();
    }

	if (!$_SERVER['PHP_AUTH_USER'] || !$_SERVER['PHP_AUTH_USER'])
	{
		$res = (!empty($_SERVER['REDIRECT_REMOTE_USER']) ? $_SERVER['REDIRECT_REMOTE_USER'] : $_SERVER['REMOTE_USER']);
		if (!empty($res) && preg_match('/(?<=(basic\s))(.*)$/is', $res, $matches))
		{
			$res = trim($matches[0]);
		    list($_SERVER["PHP_AUTH_USER"], $_SERVER["PHP_AUTH_PW"]) = explode(':', base64_decode($res));
		}
	}

	if (!is_array($GLOBALS["APPLICATION"]->arComponentMatch))
		$GLOBALS["APPLICATION"]->arComponentMatch = array();

	$GLOBALS["APPLICATION"]->arComponentMatch[] = 'webdav';
	$GLOBALS["APPLICATION"]->arComponentMatch[] = 'socialnetwork';

	define("STOP_STATISTICS", true);
	define("NO_AGENT_STATISTIC","Y");
	define("NO_AGENT_CHECK", true);
	$GLOBALS["APPLICATION"]->ShowPanel = false;

	if (CModule::IncludeModule("webdav"))
	{
		CWebDavBase::OnBeforeProlog();
	}
}
?>
