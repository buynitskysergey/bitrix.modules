<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2024 Bitrix
 */

use Bitrix\Main;
use Bitrix\Main\Session\Legacy\HealerEarlySessionStart;
use Bitrix\Main\DI\ServiceLocator;

require_once __DIR__ . "/start.php";

$application = Main\HttpApplication::getInstance();
$application->initializeExtendedKernel([
	"get" => $_GET,
	"post" => $_POST,
	"files" => $_FILES,
	"cookie" => $_COOKIE,
	"server" => $_SERVER,
	"env" => $_ENV
]);

if (class_exists('\Dev\Main\Migrator\ModuleUpdater'))
{
	\Dev\Main\Migrator\ModuleUpdater::checkUpdates('main', __DIR__);
}

if (!Main\ModuleManager::isModuleInstalled('bitrix24'))
{
	// wwall rules
	(new Main\Security\W\WWall)->handle();

	$application->addBackgroundJob([
		Main\Security\W\WWall::class, 'refreshRules'
	]);

	// vendor security notifications
	$application->addBackgroundJob([
		Main\Security\Notifications\VendorNotifier::class, 'refreshNotifications'
	]);
}

if (defined('SITE_ID'))
{
	define('LANG', SITE_ID);
}

$context = $application->getContext();
$context->initializeCulture(defined('LANG') ? LANG : null, defined('LANGUAGE_ID') ? LANGUAGE_ID : null);

// needs to be after culture initialization
$application->start();

// Register main's services
ServiceLocator::getInstance()->registerByModuleSettings('main');

// constants for compatibility
$culture = $context->getCulture();
define('SITE_CHARSET', $culture->getCharset());
define('FORMAT_DATE', $culture->getFormatDate());
define('FORMAT_DATETIME', $culture->getFormatDatetime());
define('LANG_CHARSET', SITE_CHARSET);

$site = $context->getSiteObject();
if (!defined('LANG'))
{
	define('LANG', ($site ? $site->getLid() : $context->getLanguage()));
}
define('SITE_DIR', ($site ? $site->getDir() : ''));
if (!defined('SITE_SERVER_NAME'))
{
	define('SITE_SERVER_NAME', ($site ? $site->getServerName() : ''));
}
define('LANG_DIR', SITE_DIR);

if (!defined('LANGUAGE_ID'))
{
	define('LANGUAGE_ID', $context->getLanguage());
}
define('LANG_ADMIN_LID', LANGUAGE_ID);

if (!defined('SITE_ID'))
{
	define('SITE_ID', LANG);
}

/** @global $lang */
$lang = $context->getLanguage();

//define global application object
$GLOBALS["APPLICATION"] = new CMain;

if (!defined("POST_FORM_ACTION_URI"))
{
	define("POST_FORM_ACTION_URI", htmlspecialcharsbx(GetRequestUri()));
}

$GLOBALS["MESS"] = [];
$GLOBALS["ALL_LANG_FILES"] = [];
IncludeModuleLangFile(__DIR__."/tools.php");
IncludeModuleLangFile(__FILE__);

error_reporting(COption::GetOptionInt("main", "error_reporting", E_COMPILE_ERROR | E_ERROR | E_CORE_ERROR | E_PARSE) & ~E_STRICT & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);

if (!defined("BX_COMP_MANAGED_CACHE") && COption::GetOptionString("main", "component_managed_cache_on", "Y") != "N")
{
	define("BX_COMP_MANAGED_CACHE", true);
}

// global functions
require_once __DIR__ . "/filter_tools.php";

/*ZDUyZmZMTc4YzAxMDQ3ZWY5MDU5M2FmMjIyZjJmODkxYzJmY2Q=*/$GLOBALS['_____372530977']= array(base64_decode('R'.'2V0'.'T'.'W9kdWxlRXZl'.'bnRz'),base64_decode('R'.'XhlY'.'3V'.'0ZU1vZHVsZUV2ZW50RXg='));$GLOBALS['____935181471']= array(base64_decode('ZGV'.'ma'.'W5l'),base64_decode('Ym'.'F'.'zZT'.'Y0X2RlY29k'.'Z'.'Q'.'=='),base64_decode('dW5'.'z'.'Z'.'XJp'.'YW'.'xpem'.'U'.'='),base64_decode(''.'aXN'.'f'.'Y'.'XJyYXk='),base64_decode(''.'a'.'W'.'5f'.'YXJyY'.'X'.'k='),base64_decode('c2Vy'.'aW'.'F'.'saXpl'),base64_decode('Ym'.'FzZ'.'TY0X'.'2'.'VuY29k'.'ZQ=='),base64_decode('b'.'Wt0aW'.'1l'),base64_decode('ZGF'.'0ZQ='.'='),base64_decode(''.'ZGF0Z'.'Q=='),base64_decode('c3RybGV'.'u'),base64_decode(''.'b'.'Wt0aW1'.'l'),base64_decode(''.'ZGF0ZQ='.'='),base64_decode('ZG'.'F0ZQ=='),base64_decode(''.'bWV'.'0aG9kX2'.'V4aX'.'N0cw='.'='),base64_decode('Y'.'2FsbF91c2VyX2Z1b'.'mNfYXJyYXk='),base64_decode('c3R'.'ybGVu'),base64_decode('c'.'2'.'VyaW'.'Fs'.'a'.'Xpl'),base64_decode(''.'Ym'.'FzZTY0X'.'2VuY'.'29kZ'.'Q=='),base64_decode(''.'c3R'.'ybGVu'),base64_decode(''.'aXNfYX'.'J'.'yYXk'.'='),base64_decode(''.'c'.'2Vya'.'WFsa'.'Xpl'),base64_decode(''.'Y'.'mF'.'zZT'.'Y'.'0X2VuY'.'29kZQ=='),base64_decode('c'.'2Vya'.'WF'.'saX'.'pl'),base64_decode(''.'YmFzZTY0X2VuY'.'29kZ'.'Q'.'=='),base64_decode('aXNfYXJyYXk='),base64_decode('aXNfYX'.'JyYX'.'k='),base64_decode('aW5fYXJyY'.'X'.'k='),base64_decode('aW'.'5fY'.'XJ'.'yYXk='),base64_decode('bWt0aW1l'),base64_decode('ZGF0ZQ=='),base64_decode(''.'ZG'.'F0ZQ=='),base64_decode(''.'ZGF0ZQ'.'=='),base64_decode('b'.'Wt'.'0a'.'W'.'1l'),base64_decode('ZGF0Z'.'Q'.'=='),base64_decode('Z'.'G'.'F0'.'ZQ=='),base64_decode(''.'aW'.'5f'.'YXJy'.'YXk='),base64_decode('c2V'.'yaWFsaXpl'),base64_decode(''.'Ym'.'F'.'zZTY0'.'X2VuY2'.'9'.'k'.'Z'.'Q='.'='),base64_decode(''.'aW50d'.'mFs'),base64_decode('dGltZQ=='),base64_decode('Z'.'ml'.'s'.'ZV9leGlz'.'dH'.'M'.'='),base64_decode('c3R'.'yX3'.'JlcGx'.'hY2'.'U='),base64_decode('Y2x'.'hc3NfZXhpc3Rz'),base64_decode(''.'ZG'.'V'.'m'.'aW5l'));if(!function_exists(__NAMESPACE__.'\\___815428371')){function ___815428371($_1251701299){static $_1799621967= false; if($_1799621967 == false) $_1799621967=array('SU5'.'UU'.'kFORVR'.'fRU'.'RJVElPTg'.'='.'=',''.'WQ'.'==',''.'bWFpbg==','fm'.'N'.'wZl9t'.'YXB'.'fdmFs'.'dW'.'U=','','',''.'YWx'.'sb3d'.'lZF'.'9'.'jbG'.'F'.'zc2'.'Vz','ZQ==','Z'.'g='.'=','ZQ'.'==','Rg==','W'.'A==','Zg==',''.'b'.'WFp'.'bg==','fmNwZl9'.'tYXB'.'fdm'.'FsdWU=',''.'UG'.'9'.'ydGFs','Rg==','ZQ==',''.'ZQ==','WA==','Rg='.'=','RA==',''.'RA==','bQ'.'==','ZA==','WQ='.'=','Zg==',''.'Z'.'g='.'=','Zg='.'=',''.'Zg='.'=',''.'U'.'G9ydGFs','Rg==','ZQ==','Z'.'Q==','WA'.'==','R'.'g==','RA==','R'.'A==','bQ==','ZA==','WQ==','bWF'.'p'.'bg==','T24=','U2V0dG'.'luZ'.'3'.'NDa'.'GF'.'uZ'.'2'.'U=',''.'Zg='.'=','Zg==','Zg'.'==','Zg='.'=','bWFp'.'bg='.'=',''.'fm'.'NwZl9'.'tYXBfd'.'mFs'.'dWU'.'=',''.'ZQ'.'==',''.'ZQ==',''.'RA==','ZQ==',''.'ZQ==','Zg'.'==','Zg==','Zg==','ZQ'.'==',''.'bWF'.'pbg'.'='.'=','f'.'mNwZl9tYXBfdmFsdWU=','ZQ='.'=','Z'.'g==','Zg='.'=','Zg='.'=','Z'.'g==','b'.'W'.'Fpbg'.'==','fmNw'.'Zl9tYXBfdmFsdWU=','ZQ='.'=','Z'.'g'.'==','U'.'G9y'.'d'.'GFs','UG9'.'ydGF'.'s',''.'ZQ==','ZQ==','UG9ydGFs','Rg==','WA'.'==',''.'Rg==','RA='.'=',''.'ZQ==','Z'.'Q==','RA==','bQ='.'=','ZA==','WQ==','ZQ'.'='.'=',''.'WA='.'=','ZQ==',''.'Rg'.'==','ZQ==','RA==','Zg='.'=','ZQ==','RA'.'==','ZQ==','bQ='.'=','ZA==','WQ==','Zg='.'=','Zg==','Zg==',''.'Z'.'g==','Zg'.'==','Zg==','Zg==','Zg==',''.'bWF'.'pbg==',''.'f'.'mNwZl'.'9tYXB'.'fdm'.'F'.'sdWU'.'=','Z'.'Q==',''.'ZQ='.'=',''.'UG'.'9ydGF'.'s','R'.'g==','WA'.'==','VFlQRQ==','R'.'EFURQ==','RkV'.'B'.'VF'.'VSRVM=','RVhQSVJFR'.'A==',''.'VFl'.'QR'.'Q==','RA==','V'.'FJ'.'ZX0RBWVNfQ0'.'9'.'VTlQ=',''.'REFU'.'RQ='.'=','VFJZX0RBWVN'.'fQ'.'0'.'9V'.'TlQ=','R'.'VhQS'.'VJFRA==',''.'RkVB'.'VFVSRV'.'M'.'=','Z'.'g='.'=','Zg'.'==','RE9'.'DVU1FT'.'l'.'Rf'.'Uk9PVA'.'==','L2J'.'pdHJp'.'eC9tb'.'2R1b'.'GVz'.'L'.'w'.'==','L2luc3Rh'.'bGwva'.'W5'.'kZXgu'.'cGhw','Lg==','Xw'.'==','c2V'.'h'.'cmN'.'o',''.'Tg==','','','QUNUSVZF','WQ==','c29jaWFsbmV0d29yaw==','YWxs'.'b'.'3dfZnJpZ'.'Wxk'.'cw'.'==','W'.'Q==','SUQ=','c'.'29jaWF'.'sbmV0d2'.'9ya'.'w==','YWxs'.'b'.'3d'.'fZ'.'nJpZWxkc'.'w==','SU'.'Q=','c'.'29jaW'.'Fsb'.'m'.'V0d29yaw==','YWxsb3dfZnJpZWxkcw'.'='.'=','Tg==','','','QUN'.'USVZ'.'F',''.'WQ==','c29'.'j'.'aWF'.'sbmV'.'0d'.'2'.'9y'.'aw'.'==','YWx'.'s'.'b3dfbWljcm9ib'.'G'.'9'.'nX3VzZX'.'I=','W'.'Q==',''.'SU'.'Q=','c2'.'9jaWF'.'sbm'.'V'.'0d'.'29yaw='.'=',''.'YWx'.'sb3'.'df'.'bWljcm9ib'.'G9'.'nX3VzZXI=','SUQ=','c2'.'9j'.'a'.'WF'.'sbmV0d29yaw==','Y'.'Wxs'.'b3df'.'bW'.'ljc'.'m9ibG9n'.'X3VzZXI=','c'.'29'.'j'.'aWFsbmV0d29yaw==',''.'YWxsb3'.'d'.'fbWljcm9'.'ibG9nX2dyb3Vw',''.'W'.'Q==','SU'.'Q=','c29j'.'a'.'W'.'FsbmV0d29'.'ya'.'w'.'==','YWxsb3dfbW'.'lj'.'cm'.'9ib'.'G9n'.'X'.'2dyb3Vw',''.'SUQ=','c'.'29jaW'.'Fsb'.'mV0'.'d'.'29y'.'aw'.'='.'=','Y'.'W'.'x'.'sb3dfbWl'.'jcm9ibG9'.'nX2'.'dyb3Vw',''.'Tg'.'='.'=','','','QUNUS'.'V'.'ZF','WQ='.'=','c2'.'9jaWFsbmV'.'0d29yaw==',''.'Y'.'W'.'xsb3dfZm'.'ls'.'ZXNfdX'.'Nlcg==','WQ==','SUQ=','c2'.'9jaWFs'.'bmV0d29yaw'.'==',''.'YWxsb3dfZmlsZXNfdX'.'Nlcg==','SUQ=','c29jaW'.'Fs'.'bmV0'.'d29yaw==',''.'Y'.'Wxsb3dfZmlsZXNfd'.'XNlcg==','T'.'g==','','','QUNUSVZF','WQ==','c29jaWFsbmV0d29ya'.'w='.'=','YWxsb3dfYmxv'.'Z191'.'c2Vy','WQ'.'==','SUQ=','c29'.'j'.'aWFsb'.'mV0d'.'29y'.'aw==','YWx'.'sb3df'.'Ymx'.'vZ191c2Vy',''.'SUQ=',''.'c'.'29jaW'.'FsbmV0'.'d29yaw'.'==','YWxsb3dfYmxvZ191c2Vy',''.'Tg==','','',''.'QUNUSVZF','WQ==','c2'.'9j'.'aWFs'.'bmV'.'0d'.'29y'.'aw==','Y'.'Wxsb3df'.'cGhvdG9fdXNlcg'.'==',''.'WQ==','S'.'UQ=',''.'c29'.'j'.'aWFs'.'bm'.'V0d29yaw='.'=','YWxs'.'b3dfcGh'.'v'.'dG9fdX'.'Nl'.'c'.'g='.'=','SUQ=','c29'.'j'.'a'.'WF'.'sbmV0d29y'.'aw==','YWxsb3df'.'cGhvdG'.'9fd'.'XN'.'lcg==','T'.'g='.'=','','','Q'.'UNUSVZF','WQ'.'='.'=','c29jaWFsbmV0d29yaw==',''.'Y'.'W'.'xsb3'.'dfZm9yd'.'W'.'1'.'fdXNlcg'.'==',''.'WQ==','SUQ=','c29jaW'.'Fs'.'bmV0d'.'29yaw==','YW'.'xsb3df'.'Z'.'m9y'.'dW1fdXNlcg==','SUQ=','c29j'.'aWFsbmV0d2'.'9'.'ya'.'w'.'==','YWxsb'.'3dfZm'.'9'.'yd'.'W1fdXNl'.'cg==','T'.'g==','','','Q'.'UNUSVZF',''.'WQ==','c29jaWFsbm'.'V0'.'d29ya'.'w==',''.'YWxsb3dfdGFza3N'.'fdXN'.'l'.'cg==','WQ==','SUQ'.'=','c29jaWFs'.'bmV0d29yaw==','YWxsb3'.'d'.'f'.'dGFz'.'a3'.'NfdXNlc'.'g==','SU'.'Q=',''.'c2'.'9jaWF'.'sbmV'.'0d29ya'.'w'.'='.'=',''.'YWxsb'.'3d'.'fdGF'.'za3'.'NfdXNlcg==','c'.'29'.'jaW'.'FsbmV0'.'d'.'29yaw==','YWxs'.'b3d'.'fdGFza3N'.'fZ3JvdX'.'A=',''.'WQ==',''.'SUQ=','c29jaWFsbmV0d29y'.'aw==','YWxsb3'.'dfd'.'GFza3N'.'fZ3JvdX'.'A=','SUQ=','c29ja'.'W'.'FsbmV0d29y'.'aw==','YWxsb3d'.'fd'.'GFza3'.'NfZ3'.'J'.'vdXA=','dG'.'Fza'.'3M=','T'.'g==','','','QUN'.'USV'.'ZF','WQ='.'=','c2'.'9'.'jaWFsbm'.'V0d2'.'9y'.'aw='.'=','YWxsb3'.'dfY'.'2'.'FsZW5kY'.'XJfdX'.'N'.'lcg==','WQ==','SUQ=',''.'c2'.'9jaWFs'.'bmV0d29ya'.'w==','YW'.'xsb3d'.'fY2FsZW5'.'kYXJ'.'fdX'.'Nlcg'.'='.'=','SUQ=','c29jaWFsbmV0d29y'.'aw==','Y'.'W'.'xsb3dfY2FsZW'.'5kYXJfdX'.'Nl'.'cg==','c'.'29jaWF'.'s'.'bmV0d29yaw==','YWxsb3dfY2FsZW5kY'.'XJf'.'Z3J'.'vdXA=',''.'WQ==',''.'SU'.'Q=','c'.'29jaWFs'.'bmV0d'.'29ya'.'w='.'=','Y'.'Wx'.'s'.'b'.'3dfY2FsZW5'.'kYXJf'.'Z3Jvd'.'XA'.'=',''.'SUQ'.'=','c29jaW'.'Fsbm'.'V0'.'d29y'.'aw==','YW'.'x'.'s'.'b3dfY2F'.'s'.'ZW'.'5'.'kY'.'X'.'JfZ'.'3JvdXA=','Q'.'UNU'.'S'.'VZF',''.'WQ='.'=','Tg==','ZXh0cm'.'FuZ'.'XQ=','aWJsb2Nr',''.'T'.'25BZn'.'RlcklC'.'bG9ja0VsZW1lbnRVcGRhdGU'.'=','aW50c'.'mFu'.'ZXQ=','Q0ludHJhbmV0RX'.'Zlbn'.'RIY'.'W'.'5kbGV'.'ycw'.'==','U'.'1BSZ'.'W'.'dpc'.'3'.'RlclVwZG'.'F'.'0ZW'.'RJdGV'.'t','Q0l'.'udHJhb'.'mV'.'0U2h'.'hcmVwb2lu'.'d'.'D'.'o6'.'QWdlbnRMa'.'XN0cygp'.'Ow==','aW'.'50cm'.'FuZXQ=','Tg='.'=','Q0l'.'ud'.'H'.'JhbmV0'.'U2hh'.'cmV'.'wb2'.'ludDo6QW'.'dlbnRRdWV1ZSgp'.'Ow='.'=','aW'.'50'.'c'.'mFuZXQ=','Tg==','Q'.'0ludHJhbm'.'V'.'0U2'.'hh'.'cmV'.'wb'.'2'.'lu'.'dDo'.'6QW'.'dl'.'bn'.'RVcG'.'Rhd'.'GU'.'oKTs=','aW50cmFuZXQ=','T'.'g'.'==','a'.'WJsb'.'2Nr','T25'.'BZnRlc'.'klCbG9ja'.'0'.'Vs'.'Z'.'W1'.'lbn'.'R'.'BZ'.'GQ'.'=',''.'a'.'W50cmFu'.'Z'.'XQ'.'=','Q'.'0ludHJ'.'hbm'.'V0RXZ'.'lbnRIY'.'W5'.'kbGVycw'.'==','U1BSZWd'.'pc3Rlc'.'lVwZG'.'F'.'0ZWRJ'.'d'.'GVt','aWJsb2N'.'r','T25BZnRlcklCbG9ja'.'0Vs'.'ZW'.'1lbnR'.'VcGRhd'.'GU=','aW5'.'0cmFuZXQ=','Q0lud'.'H'.'Jhb'.'mV0RXZlbnRIYW5kbGV'.'yc'.'w==','U1'.'BS'.'Z'.'Wdpc3Rl'.'clVw'.'ZGF0ZWRJdG'.'Vt','Q0l'.'ud'.'H'.'Jh'.'bmV0U'.'2'.'h'.'hcmVwb2ludD'.'o6'.'Q'.'Wd'.'lbnRMa'.'XN'.'0cygp'.'Ow==','aW50cmFuZX'.'Q=','Q'.'0ludH'.'JhbmV0U'.'2hh'.'cm'.'Vwb2ludDo'.'6QWdlb'.'nRRdWV1ZSgpOw'.'==','aW5'.'0cmFuZ'.'X'.'Q=','Q0ludHJhbmV0'.'U'.'2hhcm'.'Vwb2lu'.'dDo6QW'.'dlbnRVc'.'GR'.'hd'.'GUoKTs=','aW'.'50'.'c'.'mF'.'u'.'ZXQ'.'=','Y'.'3'.'Jt','bWF'.'pbg'.'==','T25CZ'.'WZvcmV'.'Q'.'cm9sb2c=',''.'b'.'WF'.'pb'.'g'.'==','Q1dp'.'emFyZF'.'NvbFB'.'hbmVsSW50cmFuZ'.'XQ=',''.'U2hvd'.'1Bhbm'.'Vs','L'.'21vZHV'.'sZ'.'X'.'Mv'.'aW50cmFuZ'.'XQvcG'.'FuZWxf'.'YnV0dG'.'9u'.'LnB'.'ocA==','RU5DT'.'0RF','WQ==');return base64_decode($_1799621967[$_1251701299]);}};$GLOBALS['____935181471'][0](___815428371(0), ___815428371(1));class CBXFeatures{ private static $_187116540= 30; private static $_142137123= array( "Portal" => array( "CompanyCalendar", "CompanyPhoto", "CompanyVideo", "CompanyCareer", "StaffChanges", "StaffAbsence", "CommonDocuments", "MeetingRoomBookingSystem", "Wiki", "Learning", "Vote", "WebLink", "Subscribe", "Friends", "PersonalFiles", "PersonalBlog", "PersonalPhoto", "PersonalForum", "Blog", "Forum", "Gallery", "Board", "MicroBlog", "WebMessenger",), "Communications" => array( "Tasks", "Calendar", "Workgroups", "Jabber", "VideoConference", "Extranet", "SMTP", "Requests", "DAV", "intranet_sharepoint", "timeman", "Idea", "Meeting", "EventList", "Salary", "XDImport",), "Enterprise" => array( "BizProc", "Lists", "Support", "Analytics", "crm", "Controller", "LdapUnlimitedUsers",), "Holding" => array( "Cluster", "MultiSites",),); private static $_910383404= null; private static $_18930944= null; private static function __241330840(){ if(self::$_910383404 === null){ self::$_910383404= array(); foreach(self::$_142137123 as $_1707188445 => $_1622991363){ foreach($_1622991363 as $_207250190) self::$_910383404[$_207250190]= $_1707188445;}} if(self::$_18930944 === null){ self::$_18930944= array(); $_900993337= COption::GetOptionString(___815428371(2), ___815428371(3), ___815428371(4)); if($_900993337 != ___815428371(5)){ $_900993337= $GLOBALS['____935181471'][1]($_900993337); $_900993337= $GLOBALS['____935181471'][2]($_900993337,[___815428371(6) => false]); if($GLOBALS['____935181471'][3]($_900993337)){ self::$_18930944= $_900993337;}} if(empty(self::$_18930944)){ self::$_18930944= array(___815428371(7) => array(), ___815428371(8) => array());}}} public static function InitiateEditionsSettings($_591544741){ self::__241330840(); $_997287352= array(); foreach(self::$_142137123 as $_1707188445 => $_1622991363){ $_1618484496= $GLOBALS['____935181471'][4]($_1707188445, $_591544741); self::$_18930944[___815428371(9)][$_1707188445]=($_1618484496? array(___815428371(10)): array(___815428371(11))); foreach($_1622991363 as $_207250190){ self::$_18930944[___815428371(12)][$_207250190]= $_1618484496; if(!$_1618484496) $_997287352[]= array($_207250190, false);}} $_1080055107= $GLOBALS['____935181471'][5](self::$_18930944); $_1080055107= $GLOBALS['____935181471'][6]($_1080055107); COption::SetOptionString(___815428371(13), ___815428371(14), $_1080055107); foreach($_997287352 as $_1465277455) self::__1454677107($_1465277455[(184*2-368)], $_1465277455[round(0+0.25+0.25+0.25+0.25)]);} public static function IsFeatureEnabled($_207250190){ if($_207250190 == '') return true; self::__241330840(); if(!isset(self::$_910383404[$_207250190])) return true; if(self::$_910383404[$_207250190] == ___815428371(15)) $_585046305= array(___815428371(16)); elseif(isset(self::$_18930944[___815428371(17)][self::$_910383404[$_207250190]])) $_585046305= self::$_18930944[___815428371(18)][self::$_910383404[$_207250190]]; else $_585046305= array(___815428371(19)); if($_585046305[(1376/2-688)] != ___815428371(20) && $_585046305[(906-2*453)] != ___815428371(21)){ return false;} elseif($_585046305[(134*2-268)] == ___815428371(22)){ if($_585046305[round(0+0.5+0.5)]< $GLOBALS['____935181471'][7](min(2,0,0.66666666666667),(908-2*454),(162*2-324), Date(___815428371(23)), $GLOBALS['____935181471'][8](___815428371(24))- self::$_187116540, $GLOBALS['____935181471'][9](___815428371(25)))){ if(!isset($_585046305[round(0+1+1)]) ||!$_585046305[round(0+0.4+0.4+0.4+0.4+0.4)]) self::__109410747(self::$_910383404[$_207250190]); return false;}} return!isset(self::$_18930944[___815428371(26)][$_207250190]) || self::$_18930944[___815428371(27)][$_207250190];} public static function IsFeatureInstalled($_207250190){ if($GLOBALS['____935181471'][10]($_207250190) <= 0) return true; self::__241330840(); return(isset(self::$_18930944[___815428371(28)][$_207250190]) && self::$_18930944[___815428371(29)][$_207250190]);} public static function IsFeatureEditable($_207250190){ if($_207250190 == '') return true; self::__241330840(); if(!isset(self::$_910383404[$_207250190])) return true; if(self::$_910383404[$_207250190] == ___815428371(30)) $_585046305= array(___815428371(31)); elseif(isset(self::$_18930944[___815428371(32)][self::$_910383404[$_207250190]])) $_585046305= self::$_18930944[___815428371(33)][self::$_910383404[$_207250190]]; else $_585046305= array(___815428371(34)); if($_585046305[(1288/2-644)] != ___815428371(35) && $_585046305[(940-2*470)] != ___815428371(36)){ return false;} elseif($_585046305[min(80,0,26.666666666667)] == ___815428371(37)){ if($_585046305[round(0+0.5+0.5)]< $GLOBALS['____935181471'][11](min(74,0,24.666666666667),(1280/2-640),(948-2*474), Date(___815428371(38)), $GLOBALS['____935181471'][12](___815428371(39))- self::$_187116540, $GLOBALS['____935181471'][13](___815428371(40)))){ if(!isset($_585046305[round(0+0.4+0.4+0.4+0.4+0.4)]) ||!$_585046305[round(0+2)]) self::__109410747(self::$_910383404[$_207250190]); return false;}} return true;} private static function __1454677107($_207250190, $_2033398068){ if($GLOBALS['____935181471'][14]("CBXFeatures", "On".$_207250190."SettingsChange")) $GLOBALS['____935181471'][15](array("CBXFeatures", "On".$_207250190."SettingsChange"), array($_207250190, $_2033398068)); $_765068757= $GLOBALS['_____372530977'][0](___815428371(41), ___815428371(42).$_207250190.___815428371(43)); while($_298268138= $_765068757->Fetch()) $GLOBALS['_____372530977'][1]($_298268138, array($_207250190, $_2033398068));} public static function SetFeatureEnabled($_207250190, $_2033398068= true, $_2131008104= true){ if($GLOBALS['____935181471'][16]($_207250190) <= 0) return; if(!self::IsFeatureEditable($_207250190)) $_2033398068= false; $_2033398068= (bool)$_2033398068; self::__241330840(); $_874087769=(!isset(self::$_18930944[___815428371(44)][$_207250190]) && $_2033398068 || isset(self::$_18930944[___815428371(45)][$_207250190]) && $_2033398068 != self::$_18930944[___815428371(46)][$_207250190]); self::$_18930944[___815428371(47)][$_207250190]= $_2033398068; $_1080055107= $GLOBALS['____935181471'][17](self::$_18930944); $_1080055107= $GLOBALS['____935181471'][18]($_1080055107); COption::SetOptionString(___815428371(48), ___815428371(49), $_1080055107); if($_874087769 && $_2131008104) self::__1454677107($_207250190, $_2033398068);} private static function __109410747($_1707188445){ if($GLOBALS['____935181471'][19]($_1707188445) <= 0 || $_1707188445 == "Portal") return; self::__241330840(); if(!isset(self::$_18930944[___815428371(50)][$_1707188445]) || self::$_18930944[___815428371(51)][$_1707188445][(1252/2-626)] != ___815428371(52)) return; if(isset(self::$_18930944[___815428371(53)][$_1707188445][round(0+0.4+0.4+0.4+0.4+0.4)]) && self::$_18930944[___815428371(54)][$_1707188445][round(0+1+1)]) return; $_997287352= array(); if(isset(self::$_142137123[$_1707188445]) && $GLOBALS['____935181471'][20](self::$_142137123[$_1707188445])){ foreach(self::$_142137123[$_1707188445] as $_207250190){ if(isset(self::$_18930944[___815428371(55)][$_207250190]) && self::$_18930944[___815428371(56)][$_207250190]){ self::$_18930944[___815428371(57)][$_207250190]= false; $_997287352[]= array($_207250190, false);}} self::$_18930944[___815428371(58)][$_1707188445][round(0+0.4+0.4+0.4+0.4+0.4)]= true;} $_1080055107= $GLOBALS['____935181471'][21](self::$_18930944); $_1080055107= $GLOBALS['____935181471'][22]($_1080055107); COption::SetOptionString(___815428371(59), ___815428371(60), $_1080055107); foreach($_997287352 as $_1465277455) self::__1454677107($_1465277455[(170*2-340)], $_1465277455[round(0+0.25+0.25+0.25+0.25)]);} public static function ModifyFeaturesSettings($_591544741, $_1622991363){ self::__241330840(); foreach($_591544741 as $_1707188445 => $_2119093029) self::$_18930944[___815428371(61)][$_1707188445]= $_2119093029; $_997287352= array(); foreach($_1622991363 as $_207250190 => $_2033398068){ if(!isset(self::$_18930944[___815428371(62)][$_207250190]) && $_2033398068 || isset(self::$_18930944[___815428371(63)][$_207250190]) && $_2033398068 != self::$_18930944[___815428371(64)][$_207250190]) $_997287352[]= array($_207250190, $_2033398068); self::$_18930944[___815428371(65)][$_207250190]= $_2033398068;} $_1080055107= $GLOBALS['____935181471'][23](self::$_18930944); $_1080055107= $GLOBALS['____935181471'][24]($_1080055107); COption::SetOptionString(___815428371(66), ___815428371(67), $_1080055107); self::$_18930944= false; foreach($_997287352 as $_1465277455) self::__1454677107($_1465277455[min(162,0,54)], $_1465277455[round(0+0.33333333333333+0.33333333333333+0.33333333333333)]);} public static function SaveFeaturesSettings($_278488470, $_945078654){ self::__241330840(); $_2045101320= array(___815428371(68) => array(), ___815428371(69) => array()); if(!$GLOBALS['____935181471'][25]($_278488470)) $_278488470= array(); if(!$GLOBALS['____935181471'][26]($_945078654)) $_945078654= array(); if(!$GLOBALS['____935181471'][27](___815428371(70), $_278488470)) $_278488470[]= ___815428371(71); foreach(self::$_142137123 as $_1707188445 => $_1622991363){ if(isset(self::$_18930944[___815428371(72)][$_1707188445])){ $_1179652417= self::$_18930944[___815428371(73)][$_1707188445];} else{ $_1179652417=($_1707188445 == ___815428371(74)? array(___815428371(75)): array(___815428371(76)));} if($_1179652417[(203*2-406)] == ___815428371(77) || $_1179652417[(150*2-300)] == ___815428371(78)){ $_2045101320[___815428371(79)][$_1707188445]= $_1179652417;} else{ if($GLOBALS['____935181471'][28]($_1707188445, $_278488470)) $_2045101320[___815428371(80)][$_1707188445]= array(___815428371(81), $GLOBALS['____935181471'][29](min(218,0,72.666666666667),(1068/2-534),(806-2*403), $GLOBALS['____935181471'][30](___815428371(82)), $GLOBALS['____935181471'][31](___815428371(83)), $GLOBALS['____935181471'][32](___815428371(84)))); else $_2045101320[___815428371(85)][$_1707188445]= array(___815428371(86));}} $_997287352= array(); foreach(self::$_910383404 as $_207250190 => $_1707188445){ if($_2045101320[___815428371(87)][$_1707188445][(195*2-390)] != ___815428371(88) && $_2045101320[___815428371(89)][$_1707188445][(203*2-406)] != ___815428371(90)){ $_2045101320[___815428371(91)][$_207250190]= false;} else{ if($_2045101320[___815428371(92)][$_1707188445][(1120/2-560)] == ___815428371(93) && $_2045101320[___815428371(94)][$_1707188445][round(0+0.2+0.2+0.2+0.2+0.2)]< $GLOBALS['____935181471'][33](min(234,0,78),(1032/2-516),(141*2-282), Date(___815428371(95)), $GLOBALS['____935181471'][34](___815428371(96))- self::$_187116540, $GLOBALS['____935181471'][35](___815428371(97)))) $_2045101320[___815428371(98)][$_207250190]= false; else $_2045101320[___815428371(99)][$_207250190]= $GLOBALS['____935181471'][36]($_207250190, $_945078654); if(!isset(self::$_18930944[___815428371(100)][$_207250190]) && $_2045101320[___815428371(101)][$_207250190] || isset(self::$_18930944[___815428371(102)][$_207250190]) && $_2045101320[___815428371(103)][$_207250190] != self::$_18930944[___815428371(104)][$_207250190]) $_997287352[]= array($_207250190, $_2045101320[___815428371(105)][$_207250190]);}} $_1080055107= $GLOBALS['____935181471'][37]($_2045101320); $_1080055107= $GLOBALS['____935181471'][38]($_1080055107); COption::SetOptionString(___815428371(106), ___815428371(107), $_1080055107); self::$_18930944= false; foreach($_997287352 as $_1465277455) self::__1454677107($_1465277455[min(60,0,20)], $_1465277455[round(0+1)]);} public static function GetFeaturesList(){ self::__241330840(); $_58953926= array(); foreach(self::$_142137123 as $_1707188445 => $_1622991363){ if(isset(self::$_18930944[___815428371(108)][$_1707188445])){ $_1179652417= self::$_18930944[___815428371(109)][$_1707188445];} else{ $_1179652417=($_1707188445 == ___815428371(110)? array(___815428371(111)): array(___815428371(112)));} $_58953926[$_1707188445]= array( ___815428371(113) => $_1179652417[(1020/2-510)], ___815428371(114) => $_1179652417[round(0+0.25+0.25+0.25+0.25)], ___815428371(115) => array(),); $_58953926[$_1707188445][___815428371(116)]= false; if($_58953926[$_1707188445][___815428371(117)] == ___815428371(118)){ $_58953926[$_1707188445][___815428371(119)]= $GLOBALS['____935181471'][39](($GLOBALS['____935181471'][40]()- $_58953926[$_1707188445][___815428371(120)])/ round(0+86400)); if($_58953926[$_1707188445][___815428371(121)]> self::$_187116540) $_58953926[$_1707188445][___815428371(122)]= true;} foreach($_1622991363 as $_207250190) $_58953926[$_1707188445][___815428371(123)][$_207250190]=(!isset(self::$_18930944[___815428371(124)][$_207250190]) || self::$_18930944[___815428371(125)][$_207250190]);} return $_58953926;} private static function __1743360301($_359413445, $_2005917646){ if(IsModuleInstalled($_359413445) == $_2005917646) return true; $_2025755481= $_SERVER[___815428371(126)].___815428371(127).$_359413445.___815428371(128); if(!$GLOBALS['____935181471'][41]($_2025755481)) return false; include_once($_2025755481); $_34057596= $GLOBALS['____935181471'][42](___815428371(129), ___815428371(130), $_359413445); if(!$GLOBALS['____935181471'][43]($_34057596)) return false; $_1379399180= new $_34057596; if($_2005917646){ if(!$_1379399180->InstallDB()) return false; $_1379399180->InstallEvents(); if(!$_1379399180->InstallFiles()) return false;} else{ if(CModule::IncludeModule(___815428371(131))) CSearch::DeleteIndex($_359413445); UnRegisterModule($_359413445);} return true;} protected static function OnRequestsSettingsChange($_207250190, $_2033398068){ self::__1743360301("form", $_2033398068);} protected static function OnLearningSettingsChange($_207250190, $_2033398068){ self::__1743360301("learning", $_2033398068);} protected static function OnJabberSettingsChange($_207250190, $_2033398068){ self::__1743360301("xmpp", $_2033398068);} protected static function OnVideoConferenceSettingsChange($_207250190, $_2033398068){} protected static function OnBizProcSettingsChange($_207250190, $_2033398068){ self::__1743360301("bizprocdesigner", $_2033398068);} protected static function OnListsSettingsChange($_207250190, $_2033398068){ self::__1743360301("lists", $_2033398068);} protected static function OnWikiSettingsChange($_207250190, $_2033398068){ self::__1743360301("wiki", $_2033398068);} protected static function OnSupportSettingsChange($_207250190, $_2033398068){ self::__1743360301("support", $_2033398068);} protected static function OnControllerSettingsChange($_207250190, $_2033398068){ self::__1743360301("controller", $_2033398068);} protected static function OnAnalyticsSettingsChange($_207250190, $_2033398068){ self::__1743360301("statistic", $_2033398068);} protected static function OnVoteSettingsChange($_207250190, $_2033398068){ self::__1743360301("vote", $_2033398068);} protected static function OnFriendsSettingsChange($_207250190, $_2033398068){ if($_2033398068) $_1178066192= "Y"; else $_1178066192= ___815428371(132); $_301600125= CSite::GetList(___815428371(133), ___815428371(134), array(___815428371(135) => ___815428371(136))); while($_1875507708= $_301600125->Fetch()){ if(COption::GetOptionString(___815428371(137), ___815428371(138), ___815428371(139), $_1875507708[___815428371(140)]) != $_1178066192){ COption::SetOptionString(___815428371(141), ___815428371(142), $_1178066192, false, $_1875507708[___815428371(143)]); COption::SetOptionString(___815428371(144), ___815428371(145), $_1178066192);}}} protected static function OnMicroBlogSettingsChange($_207250190, $_2033398068){ if($_2033398068) $_1178066192= "Y"; else $_1178066192= ___815428371(146); $_301600125= CSite::GetList(___815428371(147), ___815428371(148), array(___815428371(149) => ___815428371(150))); while($_1875507708= $_301600125->Fetch()){ if(COption::GetOptionString(___815428371(151), ___815428371(152), ___815428371(153), $_1875507708[___815428371(154)]) != $_1178066192){ COption::SetOptionString(___815428371(155), ___815428371(156), $_1178066192, false, $_1875507708[___815428371(157)]); COption::SetOptionString(___815428371(158), ___815428371(159), $_1178066192);} if(COption::GetOptionString(___815428371(160), ___815428371(161), ___815428371(162), $_1875507708[___815428371(163)]) != $_1178066192){ COption::SetOptionString(___815428371(164), ___815428371(165), $_1178066192, false, $_1875507708[___815428371(166)]); COption::SetOptionString(___815428371(167), ___815428371(168), $_1178066192);}}} protected static function OnPersonalFilesSettingsChange($_207250190, $_2033398068){ if($_2033398068) $_1178066192= "Y"; else $_1178066192= ___815428371(169); $_301600125= CSite::GetList(___815428371(170), ___815428371(171), array(___815428371(172) => ___815428371(173))); while($_1875507708= $_301600125->Fetch()){ if(COption::GetOptionString(___815428371(174), ___815428371(175), ___815428371(176), $_1875507708[___815428371(177)]) != $_1178066192){ COption::SetOptionString(___815428371(178), ___815428371(179), $_1178066192, false, $_1875507708[___815428371(180)]); COption::SetOptionString(___815428371(181), ___815428371(182), $_1178066192);}}} protected static function OnPersonalBlogSettingsChange($_207250190, $_2033398068){ if($_2033398068) $_1178066192= "Y"; else $_1178066192= ___815428371(183); $_301600125= CSite::GetList(___815428371(184), ___815428371(185), array(___815428371(186) => ___815428371(187))); while($_1875507708= $_301600125->Fetch()){ if(COption::GetOptionString(___815428371(188), ___815428371(189), ___815428371(190), $_1875507708[___815428371(191)]) != $_1178066192){ COption::SetOptionString(___815428371(192), ___815428371(193), $_1178066192, false, $_1875507708[___815428371(194)]); COption::SetOptionString(___815428371(195), ___815428371(196), $_1178066192);}}} protected static function OnPersonalPhotoSettingsChange($_207250190, $_2033398068){ if($_2033398068) $_1178066192= "Y"; else $_1178066192= ___815428371(197); $_301600125= CSite::GetList(___815428371(198), ___815428371(199), array(___815428371(200) => ___815428371(201))); while($_1875507708= $_301600125->Fetch()){ if(COption::GetOptionString(___815428371(202), ___815428371(203), ___815428371(204), $_1875507708[___815428371(205)]) != $_1178066192){ COption::SetOptionString(___815428371(206), ___815428371(207), $_1178066192, false, $_1875507708[___815428371(208)]); COption::SetOptionString(___815428371(209), ___815428371(210), $_1178066192);}}} protected static function OnPersonalForumSettingsChange($_207250190, $_2033398068){ if($_2033398068) $_1178066192= "Y"; else $_1178066192= ___815428371(211); $_301600125= CSite::GetList(___815428371(212), ___815428371(213), array(___815428371(214) => ___815428371(215))); while($_1875507708= $_301600125->Fetch()){ if(COption::GetOptionString(___815428371(216), ___815428371(217), ___815428371(218), $_1875507708[___815428371(219)]) != $_1178066192){ COption::SetOptionString(___815428371(220), ___815428371(221), $_1178066192, false, $_1875507708[___815428371(222)]); COption::SetOptionString(___815428371(223), ___815428371(224), $_1178066192);}}} protected static function OnTasksSettingsChange($_207250190, $_2033398068){ if($_2033398068) $_1178066192= "Y"; else $_1178066192= ___815428371(225); $_301600125= CSite::GetList(___815428371(226), ___815428371(227), array(___815428371(228) => ___815428371(229))); while($_1875507708= $_301600125->Fetch()){ if(COption::GetOptionString(___815428371(230), ___815428371(231), ___815428371(232), $_1875507708[___815428371(233)]) != $_1178066192){ COption::SetOptionString(___815428371(234), ___815428371(235), $_1178066192, false, $_1875507708[___815428371(236)]); COption::SetOptionString(___815428371(237), ___815428371(238), $_1178066192);} if(COption::GetOptionString(___815428371(239), ___815428371(240), ___815428371(241), $_1875507708[___815428371(242)]) != $_1178066192){ COption::SetOptionString(___815428371(243), ___815428371(244), $_1178066192, false, $_1875507708[___815428371(245)]); COption::SetOptionString(___815428371(246), ___815428371(247), $_1178066192);}} self::__1743360301(___815428371(248), $_2033398068);} protected static function OnCalendarSettingsChange($_207250190, $_2033398068){ if($_2033398068) $_1178066192= "Y"; else $_1178066192= ___815428371(249); $_301600125= CSite::GetList(___815428371(250), ___815428371(251), array(___815428371(252) => ___815428371(253))); while($_1875507708= $_301600125->Fetch()){ if(COption::GetOptionString(___815428371(254), ___815428371(255), ___815428371(256), $_1875507708[___815428371(257)]) != $_1178066192){ COption::SetOptionString(___815428371(258), ___815428371(259), $_1178066192, false, $_1875507708[___815428371(260)]); COption::SetOptionString(___815428371(261), ___815428371(262), $_1178066192);} if(COption::GetOptionString(___815428371(263), ___815428371(264), ___815428371(265), $_1875507708[___815428371(266)]) != $_1178066192){ COption::SetOptionString(___815428371(267), ___815428371(268), $_1178066192, false, $_1875507708[___815428371(269)]); COption::SetOptionString(___815428371(270), ___815428371(271), $_1178066192);}}} protected static function OnSMTPSettingsChange($_207250190, $_2033398068){ self::__1743360301("mail", $_2033398068);} protected static function OnExtranetSettingsChange($_207250190, $_2033398068){ $_1141586902= COption::GetOptionString("extranet", "extranet_site", ""); if($_1141586902){ $_1131477325= new CSite; $_1131477325->Update($_1141586902, array(___815428371(272) =>($_2033398068? ___815428371(273): ___815428371(274))));} self::__1743360301(___815428371(275), $_2033398068);} protected static function OnDAVSettingsChange($_207250190, $_2033398068){ self::__1743360301("dav", $_2033398068);} protected static function OntimemanSettingsChange($_207250190, $_2033398068){ self::__1743360301("timeman", $_2033398068);} protected static function Onintranet_sharepointSettingsChange($_207250190, $_2033398068){ if($_2033398068){ RegisterModuleDependences("iblock", "OnAfterIBlockElementAdd", "intranet", "CIntranetEventHandlers", "SPRegisterUpdatedItem"); RegisterModuleDependences(___815428371(276), ___815428371(277), ___815428371(278), ___815428371(279), ___815428371(280)); CAgent::AddAgent(___815428371(281), ___815428371(282), ___815428371(283), round(0+100+100+100+100+100)); CAgent::AddAgent(___815428371(284), ___815428371(285), ___815428371(286), round(0+75+75+75+75)); CAgent::AddAgent(___815428371(287), ___815428371(288), ___815428371(289), round(0+3600));} else{ UnRegisterModuleDependences(___815428371(290), ___815428371(291), ___815428371(292), ___815428371(293), ___815428371(294)); UnRegisterModuleDependences(___815428371(295), ___815428371(296), ___815428371(297), ___815428371(298), ___815428371(299)); CAgent::RemoveAgent(___815428371(300), ___815428371(301)); CAgent::RemoveAgent(___815428371(302), ___815428371(303)); CAgent::RemoveAgent(___815428371(304), ___815428371(305));}} protected static function OncrmSettingsChange($_207250190, $_2033398068){ if($_2033398068) COption::SetOptionString("crm", "form_features", "Y"); self::__1743360301(___815428371(306), $_2033398068);} protected static function OnClusterSettingsChange($_207250190, $_2033398068){ self::__1743360301("cluster", $_2033398068);} protected static function OnMultiSitesSettingsChange($_207250190, $_2033398068){ if($_2033398068) RegisterModuleDependences("main", "OnBeforeProlog", "main", "CWizardSolPanelIntranet", "ShowPanel", 100, "/modules/intranet/panel_button.php"); else UnRegisterModuleDependences(___815428371(307), ___815428371(308), ___815428371(309), ___815428371(310), ___815428371(311), ___815428371(312));} protected static function OnIdeaSettingsChange($_207250190, $_2033398068){ self::__1743360301("idea", $_2033398068);} protected static function OnMeetingSettingsChange($_207250190, $_2033398068){ self::__1743360301("meeting", $_2033398068);} protected static function OnXDImportSettingsChange($_207250190, $_2033398068){ self::__1743360301("xdimport", $_2033398068);}} $GLOBALS['____935181471'][44](___815428371(313), ___815428371(314));/**/			//Do not remove this

// Component 2.0 template engines
$GLOBALS['arCustomTemplateEngines'] = [];

// User fields manager
$GLOBALS['USER_FIELD_MANAGER'] = new CUserTypeManager;

// todo: remove global
$GLOBALS['BX_MENU_CUSTOM'] = CMenuCustom::getInstance();

if (file_exists(($_fname = __DIR__ . "/classes/general/update_db_updater.php")))
{
	$US_HOST_PROCESS_MAIN = false;
	include $_fname;
}

if (($_fname = getLocalPath("init.php")) !== false)
{
	include_once $_SERVER["DOCUMENT_ROOT"] . $_fname;
}

if (($_fname = getLocalPath("php_interface/init.php", BX_PERSONAL_ROOT)) !== false)
{
	include_once $_SERVER["DOCUMENT_ROOT"] . $_fname;
}

if (($_fname = getLocalPath("php_interface/" . SITE_ID . "/init.php", BX_PERSONAL_ROOT)) !== false)
{
	include_once $_SERVER["DOCUMENT_ROOT"] . $_fname;
}

if ((!(defined("STATISTIC_ONLY") && STATISTIC_ONLY && !str_starts_with($GLOBALS["APPLICATION"]->GetCurPage(), BX_ROOT . "/admin/"))) && COption::GetOptionString("main", "include_charset", "Y") == "Y" && LANG_CHARSET != '')
{
	header("Content-Type: text/html; charset=".LANG_CHARSET);
}

if (COption::GetOptionString("main", "set_p3p_header", "Y") == "Y")
{
	header("P3P: policyref=\"/bitrix/p3p.xml\", CP=\"NON DSP COR CUR ADM DEV PSA PSD OUR UNR BUS UNI COM NAV INT DEM STA\"");
}

$license = $application->getLicense();
header("X-Powered-CMS: Bitrix Site Manager (" . ($license->isDemoKey() ? "DEMO" : $license->getPublicHashKey()) . ")");

if (COption::GetOptionString("main", "update_devsrv", "") == "Y")
{
	header("X-DevSrv-CMS: Bitrix");
}

//agents
if (COption::GetOptionString("main", "check_agents", "Y") == "Y")
{
	$application->addBackgroundJob(["CAgent", "CheckAgents"], [], Main\Application::JOB_PRIORITY_LOW);
}

//send email events
if (COption::GetOptionString("main", "check_events", "Y") !== "N")
{
	$application->addBackgroundJob(['\Bitrix\Main\Mail\EventManager', 'checkEvents'], [], Main\Application::JOB_PRIORITY_LOW - 1);
}

$healerOfEarlySessionStart = new HealerEarlySessionStart();
$healerOfEarlySessionStart->process($application->getKernelSession());

$kernelSession = $application->getKernelSession();
$kernelSession->start();
$application->getSessionLocalStorageManager()->setUniqueId($kernelSession->getId());

foreach (GetModuleEvents("main", "OnPageStart", true) as $arEvent)
{
	ExecuteModuleEventEx($arEvent);
}

//define global user object
$GLOBALS["USER"] = new CUser;

//session control from group policy
$arPolicy = $GLOBALS["USER"]->GetSecurityPolicy();
$currTime = time();
if (
	(
		//IP address changed
		$kernelSession['SESS_IP']
		&& $arPolicy["SESSION_IP_MASK"] != ''
		&& (
			(ip2long($arPolicy["SESSION_IP_MASK"]) & ip2long($kernelSession['SESS_IP']))
			!=
			(ip2long($arPolicy["SESSION_IP_MASK"]) & ip2long($_SERVER['REMOTE_ADDR']))
		)
	)
	||
	(
		//session timeout
		$arPolicy["SESSION_TIMEOUT"] > 0
		&& $kernelSession['SESS_TIME'] > 0
		&& ($currTime - $arPolicy["SESSION_TIMEOUT"] * 60) > $kernelSession['SESS_TIME']
	)
	||
	(
		//signed session
		isset($kernelSession["BX_SESSION_SIGN"])
		&& $kernelSession["BX_SESSION_SIGN"] != bitrix_sess_sign()
	)
	||
	(
		//session manually expired, e.g. in $User->LoginHitByHash
		isSessionExpired()
	)
)
{
	$compositeSessionManager = $application->getCompositeSessionManager();
	$compositeSessionManager->destroy();

	$application->getSession()->setId(Main\Security\Random::getString(32));
	$compositeSessionManager->start();

	$GLOBALS["USER"] = new CUser;
}
$kernelSession['SESS_IP'] = $_SERVER['REMOTE_ADDR'] ?? null;
if (empty($kernelSession['SESS_TIME']))
{
	$kernelSession['SESS_TIME'] = $currTime;
}
elseif (($currTime - $kernelSession['SESS_TIME']) > 60)
{
	$kernelSession['SESS_TIME'] = $currTime;
}
if (!isset($kernelSession["BX_SESSION_SIGN"]))
{
	$kernelSession["BX_SESSION_SIGN"] = bitrix_sess_sign();
}

//session control from security module
if (
	(COption::GetOptionString("main", "use_session_id_ttl", "N") == "Y")
	&& (COption::GetOptionInt("main", "session_id_ttl", 0) > 0)
	&& !defined("BX_SESSION_ID_CHANGE")
)
{
	if (!isset($kernelSession['SESS_ID_TIME']))
	{
		$kernelSession['SESS_ID_TIME'] = $currTime;
	}
	elseif (($kernelSession['SESS_ID_TIME'] + COption::GetOptionInt("main", "session_id_ttl")) < $kernelSession['SESS_TIME'])
	{
		$compositeSessionManager = $application->getCompositeSessionManager();
		$compositeSessionManager->regenerateId();

		$kernelSession['SESS_ID_TIME'] = $currTime;
	}
}

define("BX_STARTED", true);

if (isset($kernelSession['BX_ADMIN_LOAD_AUTH']))
{
	define('ADMIN_SECTION_LOAD_AUTH', 1);
	unset($kernelSession['BX_ADMIN_LOAD_AUTH']);
}

$bRsaError = false;
$USER_LID = false;

if (!defined("NOT_CHECK_PERMISSIONS") || NOT_CHECK_PERMISSIONS !== true)
{
	$doLogout = isset($_REQUEST["logout"]) && (strtolower($_REQUEST["logout"]) == "yes");

	if ($doLogout && $GLOBALS["USER"]->IsAuthorized())
	{
		$secureLogout = (Main\Config\Option::get("main", "secure_logout", "N") == "Y");

		if (!$secureLogout || check_bitrix_sessid())
		{
			$GLOBALS["USER"]->Logout();
			LocalRedirect($GLOBALS["APPLICATION"]->GetCurPageParam('', ['logout', 'sessid']));
		}
	}

	// authorize by cookies
	if (!$GLOBALS["USER"]->IsAuthorized())
	{
		$GLOBALS["USER"]->LoginByCookies();
	}

	$arAuthResult = false;

	//http basic and digest authorization
	if (($httpAuth = $GLOBALS["USER"]->LoginByHttpAuth()) !== null)
	{
		$arAuthResult = $httpAuth;
		$GLOBALS["APPLICATION"]->SetAuthResult($arAuthResult);
	}

	//Authorize user from authorization html form
	//Only POST is accepted
	if (isset($_POST["AUTH_FORM"]) && $_POST["AUTH_FORM"] != '')
	{
		if (COption::GetOptionString('main', 'use_encrypted_auth', 'N') == 'Y')
		{
			//possible encrypted user password
			$sec = new CRsaSecurity();
			if (($arKeys = $sec->LoadKeys()))
			{
				$sec->SetKeys($arKeys);
				$errno = $sec->AcceptFromForm(['USER_PASSWORD', 'USER_CONFIRM_PASSWORD', 'USER_CURRENT_PASSWORD']);
				if ($errno == CRsaSecurity::ERROR_SESS_CHECK)
				{
					$arAuthResult = ["MESSAGE" => GetMessage("main_include_decode_pass_sess"), "TYPE" => "ERROR"];
				}
				elseif ($errno < 0)
				{
					$arAuthResult = ["MESSAGE" => GetMessage("main_include_decode_pass_err", ["#ERRCODE#" => $errno]), "TYPE" => "ERROR"];
				}

				if ($errno < 0)
				{
					$bRsaError = true;
				}
			}
		}

		if (!$bRsaError)
		{
			if (!defined("ADMIN_SECTION") || ADMIN_SECTION !== true)
			{
				$USER_LID = SITE_ID;
			}

			$_POST["TYPE"] = $_POST["TYPE"] ?? null;
			if (isset($_POST["TYPE"]) && $_POST["TYPE"] == "AUTH")
			{
				$arAuthResult = $GLOBALS["USER"]->Login(
					$_POST["USER_LOGIN"] ?? '',
					$_POST["USER_PASSWORD"] ?? '',
					$_POST["USER_REMEMBER"] ?? ''
				);
			}
			elseif (isset($_POST["TYPE"]) && $_POST["TYPE"] == "OTP")
			{
				$arAuthResult = $GLOBALS["USER"]->LoginByOtp(
					$_POST["USER_OTP"] ?? '',
					$_POST["OTP_REMEMBER"] ?? '',
					$_POST["captcha_word"] ?? '',
					$_POST["captcha_sid"] ?? ''
				);
			}
			elseif (isset($_POST["TYPE"]) && $_POST["TYPE"] == "SEND_PWD")
			{
				$arAuthResult = CUser::SendPassword(
					$_POST["USER_LOGIN"] ?? '',
					$_POST["USER_EMAIL"] ?? '',
					$USER_LID,
					$_POST["captcha_word"] ?? '',
					$_POST["captcha_sid"] ?? '',
					$_POST["USER_PHONE_NUMBER"] ?? ''
				);
			}
			elseif (isset($_POST["TYPE"]) && $_POST["TYPE"] == "CHANGE_PWD")
			{
				$arAuthResult = $GLOBALS["USER"]->ChangePassword(
					$_POST["USER_LOGIN"] ?? '',
					$_POST["USER_CHECKWORD"] ?? '',
					$_POST["USER_PASSWORD"] ?? '',
					$_POST["USER_CONFIRM_PASSWORD"] ?? '',
					$USER_LID,
					$_POST["captcha_word"] ?? '',
					$_POST["captcha_sid"] ?? '',
					true,
					$_POST["USER_PHONE_NUMBER"] ?? '',
					$_POST["USER_CURRENT_PASSWORD"] ?? ''
				);
			}

			if ($_POST["TYPE"] == "AUTH" || $_POST["TYPE"] == "OTP")
			{
				//special login form in the control panel
				if ($arAuthResult === true && defined('ADMIN_SECTION') && ADMIN_SECTION === true)
				{
					//store cookies for next hit (see CMain::GetSpreadCookieHTML())
					$GLOBALS["APPLICATION"]->StoreCookies();
					$kernelSession['BX_ADMIN_LOAD_AUTH'] = true;

					// die() follows
					CMain::FinalActions('<script>window.onload=function(){(window.BX || window.parent.BX).AUTHAGENT.setAuthResult(false);};</script>');
				}
			}
		}
		$GLOBALS["APPLICATION"]->SetAuthResult($arAuthResult);
	}
	elseif (!$GLOBALS["USER"]->IsAuthorized() && isset($_REQUEST['bx_hit_hash']))
	{
		//Authorize by unique URL
		$GLOBALS["USER"]->LoginHitByHash($_REQUEST['bx_hit_hash']);
	}
}

//logout or re-authorize the user if something importand has changed
$GLOBALS["USER"]->CheckAuthActions();

//magic short URI
if (defined("BX_CHECK_SHORT_URI") && BX_CHECK_SHORT_URI && CBXShortUri::CheckUri())
{
	//local redirect inside
	die();
}

//application password scope control
if (($applicationID = $GLOBALS["USER"]->getContext()->getApplicationId()) !== null)
{
	$appManager = Main\Authentication\ApplicationManager::getInstance();
	if ($appManager->checkScope($applicationID) !== true)
	{
		$event = new Main\Event("main", "onApplicationScopeError", ['APPLICATION_ID' => $applicationID]);
		$event->send();

		$context->getResponse()->setStatus("403 Forbidden");
		$application->end();
	}
}

//define the site template
if (!defined("ADMIN_SECTION") || ADMIN_SECTION !== true)
{
	$siteTemplate = "";
	if (!empty($_REQUEST["bitrix_preview_site_template"]) && is_string($_REQUEST["bitrix_preview_site_template"]) && $GLOBALS["USER"]->CanDoOperation('view_other_settings'))
	{
		//preview of site template
		$signer = new Main\Security\Sign\Signer();
		try
		{
			//protected by a sign
			$requestTemplate = $signer->unsign($_REQUEST["bitrix_preview_site_template"], "template_preview".bitrix_sessid());

			$aTemplates = CSiteTemplate::GetByID($requestTemplate);
			if ($template = $aTemplates->Fetch())
			{
				$siteTemplate = $template["ID"];

				//preview of unsaved template
				if (isset($_GET['bx_template_preview_mode']) && $_GET['bx_template_preview_mode'] == 'Y' && $GLOBALS["USER"]->CanDoOperation('edit_other_settings'))
				{
					define("SITE_TEMPLATE_PREVIEW_MODE", true);
				}
			}
		}
		catch (Main\Security\Sign\BadSignatureException)
		{
		}
	}
	if ($siteTemplate == "")
	{
		$siteTemplate = CSite::GetCurTemplate();
	}

	if (!defined('SITE_TEMPLATE_ID'))
	{
		define("SITE_TEMPLATE_ID", $siteTemplate);
	}

	define("SITE_TEMPLATE_PATH", getLocalPath('templates/'.SITE_TEMPLATE_ID, BX_PERSONAL_ROOT));
}
else
{
	// prevents undefined constants
	if (!defined('SITE_TEMPLATE_ID'))
	{
		define('SITE_TEMPLATE_ID', '.default');
	}

	define('SITE_TEMPLATE_PATH', '/bitrix/templates/.default');
}

//magic parameters: show page creation time
if (isset($_GET["show_page_exec_time"]))
{
	if ($_GET["show_page_exec_time"] == "Y" || $_GET["show_page_exec_time"] == "N")
	{
		$kernelSession["SESS_SHOW_TIME_EXEC"] = $_GET["show_page_exec_time"];
	}
}

//magic parameters: show included file processing time
if (isset($_GET["show_include_exec_time"]))
{
	if ($_GET["show_include_exec_time"] == "Y" || $_GET["show_include_exec_time"] == "N")
	{
		$kernelSession["SESS_SHOW_INCLUDE_TIME_EXEC"] = $_GET["show_include_exec_time"];
	}
}

//magic parameters: show include areas
if (!empty($_GET["bitrix_include_areas"]))
{
	$GLOBALS["APPLICATION"]->SetShowIncludeAreas($_GET["bitrix_include_areas"]=="Y");
}

//magic sound
if ($GLOBALS["USER"]->IsAuthorized())
{
	$cookie_prefix = COption::GetOptionString('main', 'cookie_name', 'BITRIX_SM');
	if (!isset($_COOKIE[$cookie_prefix.'_SOUND_LOGIN_PLAYED']))
	{
		$GLOBALS["APPLICATION"]->set_cookie('SOUND_LOGIN_PLAYED', 'Y', 0);
	}
}

//magic cache
Main\Composite\Engine::shouldBeEnabled();

// should be before proactive filter on OnBeforeProlog
$userPassword = $_POST["USER_PASSWORD"] ?? null;
$userConfirmPassword = $_POST["USER_CONFIRM_PASSWORD"] ?? null;

foreach(GetModuleEvents("main", "OnBeforeProlog", true) as $arEvent)
{
	ExecuteModuleEventEx($arEvent);
}

// need to reinit
$GLOBALS["APPLICATION"]->SetCurPage(false);

if (!defined("NOT_CHECK_PERMISSIONS") || NOT_CHECK_PERMISSIONS !== true)
{
	//Register user from authorization html form
	//Only POST is accepted
	if (isset($_POST["AUTH_FORM"]) && $_POST["AUTH_FORM"] != '' && isset($_POST["TYPE"]) && $_POST["TYPE"] == "REGISTRATION")
	{
		if (!$bRsaError)
		{
			if (COption::GetOptionString("main", "new_user_registration", "N") == "Y" && (!defined("ADMIN_SECTION") || ADMIN_SECTION !== true))
			{
				$arAuthResult = $GLOBALS["USER"]->Register(
					$_POST["USER_LOGIN"] ?? '',
					$_POST["USER_NAME"] ?? '',
					$_POST["USER_LAST_NAME"] ?? '',
					$userPassword,
					$userConfirmPassword,
					$_POST["USER_EMAIL"] ?? '',
					$USER_LID,
					$_POST["captcha_word"] ?? '',
					$_POST["captcha_sid"] ?? '',
					false,
					$_POST["USER_PHONE_NUMBER"] ?? ''
				);

				$GLOBALS["APPLICATION"]->SetAuthResult($arAuthResult);
			}
		}
	}
}

if ((!defined("NOT_CHECK_PERMISSIONS") || NOT_CHECK_PERMISSIONS !== true) && (!defined("NOT_CHECK_FILE_PERMISSIONS") || NOT_CHECK_FILE_PERMISSIONS !== true))
{
	$real_path = $context->getRequest()->getScriptFile();

	if (!$GLOBALS["USER"]->CanDoFileOperation('fm_view_file', [SITE_ID, $real_path]) || (defined("NEED_AUTH") && NEED_AUTH && !$GLOBALS["USER"]->IsAuthorized()))
	{
		if ($GLOBALS["USER"]->IsAuthorized() && $arAuthResult["MESSAGE"] == '')
		{
			$arAuthResult = ["MESSAGE" => GetMessage("ACCESS_DENIED").' '.GetMessage("ACCESS_DENIED_FILE", ["#FILE#" => $real_path]), "TYPE" => "ERROR"];

			if (COption::GetOptionString("main", "event_log_permissions_fail", "N") === "Y")
			{
				CEventLog::Log("SECURITY", "USER_PERMISSIONS_FAIL", "main", $GLOBALS["USER"]->GetID(), $real_path);
			}
		}

		if (defined("ADMIN_SECTION") && ADMIN_SECTION === true)
		{
			if (isset($_REQUEST["mode"]) && ($_REQUEST["mode"] === "list" || $_REQUEST["mode"] === "settings"))
			{
				echo "<script>top.location='".$GLOBALS["APPLICATION"]->GetCurPage()."?".DeleteParam(["mode"])."';</script>";
				die();
			}
			elseif (isset($_REQUEST["mode"]) && $_REQUEST["mode"] === "frame")
			{
				echo "<script>
					const w = (opener? opener.window:parent.window);
					w.location.href='" .$GLOBALS["APPLICATION"]->GetCurPage()."?".DeleteParam(["mode"])."';
				</script>";
				die();
			}
			elseif (defined("MOBILE_APP_ADMIN") && MOBILE_APP_ADMIN === true)
			{
				echo json_encode(["status" => "failed"]);
				die();
			}
		}

		/** @noinspection PhpUndefinedVariableInspection */
		$GLOBALS["APPLICATION"]->AuthForm($arAuthResult);
	}
}

/*ZDUyZmZNDc2ZWFkMzc0ZGU3ODUxODdjNmVkMjVhMWViODM1NjY=*/$GLOBALS['____1213465116']= array(base64_decode('bXRfc'.'mF'.'uZ'.'A=='),base64_decode(''.'Y2'.'Fs'.'bF91c2VyX2'.'Z1bmM'.'='),base64_decode('c3RycG9z'),base64_decode('ZXhwb'.'G9kZQ'.'='.'='),base64_decode('cGFjaw'.'=='),base64_decode('bWQ1'),base64_decode('Y29'.'uc'.'3'.'R'.'hb'.'nQ='),base64_decode('aGF'.'z'.'aF9o'.'b'.'WFj'),base64_decode('c3R'.'yY'.'21w'),base64_decode('Y2Fs'.'bF91c'.'2V'.'yX2Z1bmM'.'='),base64_decode(''.'Y2FsbF91'.'c2VyX2Z1b'.'mM'.'='),base64_decode('a'.'XN'.'fb2JqZW'.'N0'),base64_decode('Y2FsbF91c2VyX2Z1'.'b'.'m'.'M'.'='),base64_decode('Y2'.'FsbF91c2VyX2Z1bmM'.'='),base64_decode('Y2'.'Fs'.'bF91c'.'2VyX2Z1bmM='),base64_decode('Y2F'.'sbF91'.'c'.'2'.'V'.'yX2Z1bmM='),base64_decode(''.'Y2FsbF91c2Vy'.'X'.'2Z1b'.'mM='),base64_decode('Y2Fs'.'bF'.'91c2V'.'yX2Z1'.'b'.'m'.'M='));if(!function_exists(__NAMESPACE__.'\\___1257862299')){function ___1257862299($_1960359005){static $_519810749= false; if($_519810749 == false) $_519810749=array('XENPcHRp'.'b24'.'6OkdldE9wdG'.'lvbl'.'N0cm'.'luZw==','bWF'.'pb'.'g==','flBBUkFNX0'.'1BW'.'F9'.'VU0'.'V'.'SUw==','Lg==','Lg==','SC'.'o=','Yml0cml4','TE'.'l'.'DRU5TRV9LRVk=','c'.'2hhMjU2','XENPcHRpb'.'24'.'6O'.'k'.'dldE9wdGl'.'vb'.'l'.'N0c'.'m'.'lu'.'Zw==','bWFpbg==','UEF'.'SQU1fTUFYX1VTRVJ'.'T','X'.'EJp'.'dHJpe'.'FxNY'.'Wl'.'uX'.'ENvbmZp'.'Z1xPc'.'HR'.'pb246OnNldA='.'=','bWFpbg'.'==','UEFSQ'.'U1'.'fTUFYX1VTRVJT','VVNF'.'Ug==','VVNF'.'U'.'g='.'=','VV'.'NFU'.'g==','SXN'.'Bd'.'XRob3'.'J'.'pemVk','VVNF'.'Ug==','SXNBZG1pbg'.'==',''.'QVB'.'Q'.'TElDQVRJT0'.'4=','Um'.'VzdGFyd'.'EJ1'.'Z'.'mZlc'.'g==',''.'TG9'.'j'.'YWxSZW'.'Rpc'.'mV'.'j'.'dA==','L'.'2xpY2Vuc2Vfc'.'mVzdHJpY3R'.'pb24ucGhw','XENPcH'.'R'.'pb2'.'46OkdldE'.'9wdGlv'.'blN'.'0c'.'mluZ'.'w'.'==','bWF'.'pb'.'g='.'=','UE'.'FSQU'.'1fT'.'UFYX'.'1VTR'.'VJT','XEJpd'.'H'.'JpeFxNY'.'WluXENvb'.'mZp'.'Z1x'.'PcHR'.'pb'.'246OnNldA==','bWFpbg'.'==','UEFS'.'QU1fTUFYX1VTRVJ'.'T');return base64_decode($_519810749[$_1960359005]);}};if($GLOBALS['____1213465116'][0](round(0+0.5+0.5), round(0+6.6666666666667+6.6666666666667+6.6666666666667)) == round(0+1.4+1.4+1.4+1.4+1.4)){ $_1927252211= $GLOBALS['____1213465116'][1](___1257862299(0), ___1257862299(1), ___1257862299(2)); if(!empty($_1927252211) && $GLOBALS['____1213465116'][2]($_1927252211, ___1257862299(3)) !== false){ list($_72930798, $_455546887)= $GLOBALS['____1213465116'][3](___1257862299(4), $_1927252211); $_1358392607= $GLOBALS['____1213465116'][4](___1257862299(5), $_72930798); $_1093547609= ___1257862299(6).$GLOBALS['____1213465116'][5]($GLOBALS['____1213465116'][6](___1257862299(7))); $_142245909= $GLOBALS['____1213465116'][7](___1257862299(8), $_455546887, $_1093547609, true); if($GLOBALS['____1213465116'][8]($_142245909, $_1358392607) !== min(152,0,50.666666666667)){ if($GLOBALS['____1213465116'][9](___1257862299(9), ___1257862299(10), ___1257862299(11)) != round(0+4+4+4)){ $GLOBALS['____1213465116'][10](___1257862299(12), ___1257862299(13), ___1257862299(14), round(0+12));} if(isset($GLOBALS[___1257862299(15)]) && $GLOBALS['____1213465116'][11]($GLOBALS[___1257862299(16)]) && $GLOBALS['____1213465116'][12](array($GLOBALS[___1257862299(17)], ___1257862299(18))) &&!$GLOBALS['____1213465116'][13](array($GLOBALS[___1257862299(19)], ___1257862299(20)))){ $GLOBALS['____1213465116'][14](array($GLOBALS[___1257862299(21)], ___1257862299(22))); $GLOBALS['____1213465116'][15](___1257862299(23), ___1257862299(24), true);}}} else{ if($GLOBALS['____1213465116'][16](___1257862299(25), ___1257862299(26), ___1257862299(27)) != round(0+4+4+4)){ $GLOBALS['____1213465116'][17](___1257862299(28), ___1257862299(29), ___1257862299(30), round(0+2.4+2.4+2.4+2.4+2.4));}}}/**/       //Do not remove this

