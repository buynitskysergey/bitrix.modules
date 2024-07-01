<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2024 Bitrix
 */

use Bitrix\Main;
use Bitrix\Main\Session\Legacy\HealerEarlySessionStart;

require_once(__DIR__."/start.php");

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

if (!defined("BX_COMP_MANAGED_CACHE") && COption::GetOptionString("main", "component_managed_cache_on", "Y") <> "N")
{
	define("BX_COMP_MANAGED_CACHE", true);
}

// global functions
require_once(__DIR__."/filter_tools.php");

/*ZDUyZmZNDdjYzkxNDJjNTE5NWY2YWE3ODVlOGM4ZjM1MTdiMDA=*/$GLOBALS['_____198762424']= array(base64_decode('R'.'2V0TW9'.'k'.'dWxl'.'RXZ'.'lbnRz'),base64_decode('RXh'.'lY3V0'.'ZU1vZHV'.'s'.'ZUV2ZW50RXg'.'='));$GLOBALS['____1777011664']= array(base64_decode('ZG'.'Vm'.'a'.'W5'.'l'),base64_decode('Ym'.'F'.'zZTY0X2R'.'l'.'Y29kZQ='.'='),base64_decode(''.'d'.'W5zZXJpYWxpe'.'mU='),base64_decode('a'.'X'.'Nf'.'YXJyYXk='),base64_decode('aW5'.'fYXJyY'.'Xk='),base64_decode('c'.'2Vy'.'aWFsa'.'Xpl'),base64_decode(''.'YmFzZTY0X2V'.'uY29k'.'ZQ=='),base64_decode('bWt0'.'aW1l'),base64_decode(''.'Z'.'GF'.'0ZQ=='),base64_decode('Z'.'GF0ZQ=='),base64_decode('c3Ry'.'bGV'.'u'),base64_decode('bWt0aW'.'1l'),base64_decode('ZGF'.'0ZQ='.'='),base64_decode('ZGF0Z'.'Q=='),base64_decode('b'.'WV0aG9kX2V4'.'aXN0cw='.'='),base64_decode('Y'.'2FsbF91c2VyX2Z1'.'bm'.'NfY'.'X'.'J'.'yYXk'.'='),base64_decode('c3Ryb'.'G'.'Vu'),base64_decode('c2'.'VyaWF'.'s'.'aXpl'),base64_decode('YmF'.'zZ'.'TY0'.'X2'.'Vu'.'Y2'.'9kZQ'.'=='),base64_decode(''.'c'.'3RybG'.'V'.'u'),base64_decode(''.'aXNfYX'.'J'.'yYXk='),base64_decode('c2V'.'yaW'.'FsaXp'.'l'),base64_decode(''.'YmF'.'zZTY'.'0'.'X2'.'VuY'.'29kZ'.'Q'.'=='),base64_decode('c2'.'Vya'.'WF'.'s'.'aXpl'),base64_decode('YmF'.'zZTY0X2VuY29'.'kZQ='.'='),base64_decode('a'.'XNfY'.'XJy'.'YXk='),base64_decode('aX'.'NfY'.'X'.'JyYXk='),base64_decode('aW5fYXJ'.'yYXk='),base64_decode(''.'aW5fYXJy'.'YXk'.'='),base64_decode('bWt'.'0aW1l'),base64_decode(''.'ZGF'.'0ZQ=='),base64_decode('Z'.'GF0Z'.'Q=='),base64_decode('ZGF0ZQ=='),base64_decode('bWt0a'.'W1l'),base64_decode('ZGF0Z'.'Q=='),base64_decode('ZG'.'F0ZQ='.'='),base64_decode('aW5fYXJyYXk='),base64_decode('c2'.'VyaWFsaXpl'),base64_decode('Y'.'mF'.'zZ'.'TY0X'.'2Vu'.'Y'.'2'.'9kZQ=='),base64_decode('aW50dm'.'Fs'),base64_decode('dGltZQ='.'='),base64_decode(''.'Zmls'.'ZV9l'.'eGlzdHM='),base64_decode('c3RyX3'.'JlcGxhY2'.'U='),base64_decode('Y2xhc3NfZ'.'Xhpc3Rz'),base64_decode('ZGV'.'maW'.'5l'));if(!function_exists(__NAMESPACE__.'\\___960834704')){function ___960834704($_1404828573){static $_1980662625= false; if($_1980662625 == false) $_1980662625=array(''.'SU5'.'UU'.'kFORVR'.'fRUR'.'J'.'V'.'El'.'PTg==','WQ'.'==','bWFp'.'bg='.'=','fmNwZ'.'l'.'9tYXBf'.'dmFs'.'dWU=','','','YWx'.'sb3dlZF9j'.'bGFzc2Vz','Z'.'Q'.'==','Zg'.'='.'=',''.'ZQ==','R'.'g'.'==','WA='.'=','Zg==','bWFp'.'bg==','fmNwZl9tYXBf'.'d'.'mFsdWU=','UG9y'.'dGF'.'s','Rg==','Z'.'Q==',''.'Z'.'Q'.'='.'=','WA'.'='.'=','R'.'g'.'==','R'.'A==','RA==','b'.'Q==','ZA='.'=','WQ==','Zg'.'==',''.'Zg'.'==','Zg==','Zg==','UG9ydGFs','Rg==','Z'.'Q==','ZQ==',''.'WA='.'=','Rg==','RA==',''.'RA='.'=',''.'bQ==','ZA==','WQ==','b'.'WFpb'.'g==','T24'.'=','U2V0'.'dGluZ3N'.'D'.'aGFuZ2'.'U=','Zg==',''.'Zg==','Z'.'g==','Z'.'g'.'==','bWFp'.'bg==','fm'.'NwZl9tYXBfdmFsdWU=','ZQ==','ZQ==','RA==','ZQ==','ZQ'.'==','Zg==','Z'.'g==','Zg==',''.'ZQ='.'=',''.'bWFp'.'bg==','fmNw'.'Zl9tY'.'X'.'BfdmFsd'.'WU=',''.'ZQ'.'='.'=','Zg'.'='.'=','Zg='.'=','Zg'.'==','Z'.'g'.'==','bWFpbg'.'='.'=',''.'fmN'.'wZl'.'9'.'tYXBfdm'.'FsdWU=',''.'Z'.'Q==','Z'.'g='.'=','UG9ydGFs','UG'.'9y'.'dGFs',''.'ZQ'.'==','ZQ'.'==','UG9ydGFs','R'.'g==','WA==','Rg==','RA==','ZQ'.'='.'=','ZQ==','RA='.'=','bQ'.'='.'=','ZA==','WQ==','Z'.'Q='.'=','WA'.'='.'=','ZQ='.'=',''.'Rg==','ZQ='.'=','RA==','Zg'.'==','Z'.'Q==','RA'.'==','ZQ==',''.'bQ==','ZA'.'==','WQ==','Zg='.'=','Zg'.'==','Zg='.'=','Zg==','Zg'.'==','Zg==','Zg='.'=','Zg==','bWFpbg='.'=','fmNw'.'Zl9tY'.'XBfdmFsdWU=','ZQ==',''.'ZQ'.'==','UG9ydGFs','R'.'g'.'='.'=',''.'W'.'A='.'=','V'.'Fl'.'QRQ==','RE'.'FURQ==','RkVBVFVSR'.'VM=','RVhQSVJFRA==','VFlQRQ='.'=','RA='.'=',''.'VF'.'JZX0R'.'BWVNfQ09'.'V'.'TlQ=','REFURQ==','VF'.'JZX0RBWV'.'NfQ09'.'VTlQ=','RVhQSV'.'JFRA==','R'.'kVB'.'VFV'.'S'.'RVM=','Zg'.'='.'=','Zg==','RE9DVU1FTlR'.'fUk'.'9PVA==','L2J'.'pdHJpeC9tb2R1b'.'GVzLw'.'==','L2luc'.'3Rh'.'b'.'GwvaW5'.'kZXgu'.'c'.'Ghw',''.'Lg==',''.'Xw='.'=','c'.'2V'.'hc'.'mNo',''.'Tg==','','','QUNU'.'SVZF',''.'WQ='.'=','c29jaWFsbmV0d'.'29yaw==',''.'YWxsb3'.'dfZ'.'nJpZWxkcw==','WQ='.'=','SUQ=','c2'.'9'.'jaWF'.'sbmV0d29yaw==','YWxsb3d'.'fZn'.'JpZWxk'.'cw==','SUQ=','c29jaWFsbm'.'V0'.'d29'.'yaw='.'=','YWx'.'s'.'b3dfZ'.'nJpZ'.'Wxkcw==','T'.'g='.'=','','',''.'Q'.'UN'.'U'.'SV'.'ZF','WQ==','c29jaWFs'.'bmV'.'0d29ya'.'w'.'==','YWx'.'sb3'.'dfbWljc'.'m9ib'.'G9nX3'.'VzZXI=','WQ==','SUQ=','c2'.'9jaWFsbmV'.'0d29yaw==','YWxs'.'b'.'3df'.'bWlj'.'c'.'m9i'.'bG9'.'nX3V'.'zZXI'.'=','S'.'UQ=',''.'c29ja'.'WF'.'s'.'bmV'.'0'.'d'.'29ya'.'w==','YWxsb3dfbWljcm9'.'ib'.'G9nX3VzZXI'.'=','c'.'2'.'9jaWFsbmV0d29yaw==','YW'.'x'.'s'.'b3dfbWlj'.'c'.'m9ibG9'.'nX'.'2dyb'.'3Vw','WQ==','SUQ=','c'.'29jaWFsbm'.'V0d29'.'yaw==','YWxsb3d'.'f'.'bWljcm9ib'.'G9nX2dyb3'.'Vw',''.'SUQ=','c'.'29ja'.'WFsbm'.'V0d2'.'9yaw==',''.'YWxsb'.'3'.'df'.'bWljcm9ibG9'.'nX2dyb'.'3Vw',''.'T'.'g==','','',''.'QU'.'NUSVZF','WQ='.'=','c29jaWFsb'.'mV0d2'.'9y'.'aw==','YWx'.'s'.'b'.'3dfZmls'.'ZXNf'.'dXNlcg==','W'.'Q==',''.'S'.'UQ=','c29jaW'.'FsbmV'.'0d29yaw==','YWxsb3d'.'fZ'.'mlsZ'.'XNf'.'dXNl'.'cg==',''.'SUQ=','c'.'29'.'ja'.'WFsb'.'mV0d29y'.'aw='.'=','YWxsb3dfZmlsZXNfd'.'XNl'.'cg==',''.'Tg='.'=','','','QUN'.'U'.'SVZF',''.'WQ==','c29jaWFsbmV0d29yaw==','YWxs'.'b'.'3'.'dfYmxvZ'.'191c2Vy',''.'WQ==','SUQ=','c29j'.'aWFsb'.'mV0d29yaw==','Y'.'Wxsb3d'.'f'.'Y'.'mxv'.'Z191c2Vy',''.'SUQ=','c2'.'9jaWFsbm'.'V0d2'.'9yaw==','YWxsb'.'3dfYmxvZ191c2Vy','Tg==','','','QUN'.'USVZF','WQ'.'==','c29jaWFsbm'.'V0'.'d29yaw'.'==','YWx'.'s'.'b'.'3'.'d'.'fcGhvdG'.'9fd'.'XNl'.'cg==','WQ==','SU'.'Q=','c2'.'9jaWF'.'s'.'b'.'m'.'V0d29y'.'aw==','Y'.'W'.'x'.'sb3dfcGh'.'vdG9fd'.'X'.'Nlcg==','S'.'UQ=','c'.'29j'.'a'.'WFsb'.'mV0d29y'.'aw==','YWxsb'.'3dfcGhvdG9'.'f'.'dX'.'Nlc'.'g==','Tg==','','','QU'.'N'.'U'.'SVZF','WQ==','c29'.'ja'.'WFsbmV0d'.'2'.'9ya'.'w='.'=',''.'YW'.'x'.'sb3dfZm9'.'ydW'.'1fdX'.'Nlc'.'g'.'==','W'.'Q==','SUQ=','c29jaWF'.'sbmV0d29yaw==',''.'YW'.'x'.'s'.'b3d'.'fZm9ydW1'.'fdXNlcg==',''.'SUQ=','c'.'29j'.'aW'.'Fsb'.'m'.'V'.'0d29y'.'aw==','YWx'.'sb'.'3dfZm9ydW1fdXNl'.'cg==','Tg==','','','QUNUSV'.'ZF','WQ==','c29jaWFsbmV'.'0d2'.'9'.'y'.'aw='.'=','YWxs'.'b3'.'df'.'dGF'.'za3NfdXNlcg'.'==',''.'WQ==','SUQ'.'=','c29jaW'.'Fsbm'.'V0d29'.'yaw'.'==','YWxs'.'b3d'.'fdGFza3Nf'.'dX'.'Nlcg==','SUQ=','c2'.'9'.'jaWFsbmV'.'0'.'d'.'29'.'ya'.'w==','YWx'.'sb3'.'dfdG'.'F'.'za3NfdXNlcg==',''.'c'.'2'.'9jaWFsbmV0d'.'2'.'9yaw==','YWxs'.'b'.'3df'.'d'.'GFz'.'a3NfZ3JvdX'.'A'.'=','WQ==',''.'SUQ'.'=','c'.'29'.'jaWF'.'sbmV0d29ya'.'w==','YW'.'xsb'.'3dfd'.'GFza3NfZ3'.'J'.'vdXA=',''.'SUQ=','c29j'.'aW'.'Fsbm'.'V0'.'d'.'2'.'9y'.'aw='.'=','YW'.'xs'.'b3dfdGFz'.'a'.'3NfZ3JvdXA=','dGF'.'za3M=','Tg='.'=','','','QUNUSVZF','WQ==','c29'.'j'.'a'.'WFsbmV0d29y'.'a'.'w='.'=','Y'.'W'.'xsb3dfY2FsZW5kYX'.'Jf'.'dXNlcg==','WQ==','SU'.'Q'.'=','c2'.'9'.'ja'.'WFsbmV0d29ya'.'w==',''.'YW'.'xsb3df'.'Y2FsZW5kY'.'X'.'J'.'fdXNlc'.'g'.'='.'=',''.'S'.'UQ'.'=',''.'c'.'2'.'9ja'.'WFsbm'.'V0d29y'.'aw==','YW'.'xsb3dfY2Fs'.'ZW5kYXJfdXNlc'.'g='.'=','c29jaWFsb'.'mV0d'.'29yaw='.'=','YWxs'.'b3df'.'Y2F'.'s'.'ZW5kYX'.'J'.'fZ3Jvd'.'XA=','WQ==',''.'SUQ'.'=','c29jaWFsb'.'mV0d2'.'9yaw==','YW'.'xs'.'b3'.'df'.'Y2'.'Fs'.'Z'.'W5kY'.'XJf'.'Z3JvdXA=','SUQ=','c'.'29jaWFsbmV0d29yaw==','YWxsb'.'3df'.'Y2'.'FsZW5k'.'YXJfZ'.'3'.'JvdXA=','QUNUSV'.'ZF','WQ==','Tg==','ZXh0'.'cm'.'F'.'uZXQ=','aWJ'.'sb'.'2Nr','T25BZ'.'nRlcklC'.'b'.'G'.'9ja0VsZW1lb'.'n'.'RVc'.'GRhd'.'GU'.'=','aW50cmFu'.'ZXQ=',''.'Q0ludHJhbmV0RXZlb'.'nRIY'.'W5kb'.'GVycw'.'==','U'.'1B'.'S'.'ZWdp'.'c3RlclVwZGF0ZW'.'RJdGVt','Q0ludHJh'.'bm'.'V0U2hhc'.'mV'.'w'.'b2'.'lu'.'dDo6QWd'.'lbnRMaXN0cygpO'.'w'.'='.'=','aW50'.'cmFuZXQ=','Tg==','Q0l'.'udH'.'J'.'hbmV'.'0U2hhcmVwb2lud'.'Do6QWdl'.'b'.'n'.'RR'.'dWV1ZSg'.'pO'.'w==',''.'aW50c'.'mFuZXQ'.'=','T'.'g==','Q'.'0ludHJhbmV0U2'.'hhcmV'.'wb2ludD'.'o'.'6Q'.'W'.'dlbnRVcGRhdGUoKTs=','aW50cmFuZXQ=',''.'T'.'g==','aW'.'Js'.'b2Nr','T'.'25BZ'.'nRlcklCb'.'G9'.'ja0VsZW1lb'.'n'.'R'.'B'.'Z'.'G'.'Q=',''.'a'.'W50cmFuZXQ=',''.'Q0ludHJh'.'bmV'.'0RXZlbnRIYW5kbGVycw==',''.'U1BS'.'Z'.'Wdpc'.'3'.'Rlcl'.'VwZ'.'GF0ZWRJd'.'GVt','aW'.'Jsb2'.'Nr','T25B'.'Z'.'nR'.'lcklCbG9ja0Vs'.'ZW1lbnRVc'.'GRhdGU=','aW'.'50cmFuZ'.'XQ=','Q0'.'ludHJh'.'bm'.'V'.'0RX'.'Zl'.'bnR'.'IY'.'W'.'5kbG'.'Vy'.'cw==','U'.'1B'.'SZWd'.'pc3Rlcl'.'VwZGF0ZW'.'R'.'JdG'.'Vt','Q0lud'.'HJ'.'hb'.'mV0U2hhc'.'mVwb2lu'.'dD'.'o6QWdlbnRMa'.'XN0cygpOw==','aW5'.'0cmF'.'u'.'ZXQ=',''.'Q0ludHJhbmV0U2'.'hh'.'cm'.'Vwb2'.'lu'.'dDo6'.'QWdlbnRRdWV1ZSgp'.'Ow='.'=',''.'aW5'.'0cmFuZXQ=','Q0'.'ludHJ'.'hbmV0U'.'2hhcmVwb2ludDo6QWdlbnR'.'VcG'.'RhdGUoKTs'.'=','aW'.'5'.'0c'.'mFuZXQ=','Y'.'3Jt','bWFpbg==','T'.'25C'.'ZWZvcm'.'VQ'.'cm'.'9sb2c=',''.'bW'.'Fpb'.'g==','Q1'.'dpe'.'mFyZF'.'Nv'.'bFBhbmVs'.'SW50cmFuZXQ=',''.'U'.'2hv'.'d1Bhb'.'mVs','L'.'21vZH'.'Vs'.'ZXMvaW50cmFuZXQ'.'vcGFuZW'.'xf'.'Yn'.'V'.'0dG9uLnBoc'.'A'.'==','R'.'U5DT0RF','WQ='.'=');return base64_decode($_1980662625[$_1404828573]);}};$GLOBALS['____1777011664'][0](___960834704(0), ___960834704(1));class CBXFeatures{ private static $_385817641= 30; private static $_774279057= array( "Portal" => array( "CompanyCalendar", "CompanyPhoto", "CompanyVideo", "CompanyCareer", "StaffChanges", "StaffAbsence", "CommonDocuments", "MeetingRoomBookingSystem", "Wiki", "Learning", "Vote", "WebLink", "Subscribe", "Friends", "PersonalFiles", "PersonalBlog", "PersonalPhoto", "PersonalForum", "Blog", "Forum", "Gallery", "Board", "MicroBlog", "WebMessenger",), "Communications" => array( "Tasks", "Calendar", "Workgroups", "Jabber", "VideoConference", "Extranet", "SMTP", "Requests", "DAV", "intranet_sharepoint", "timeman", "Idea", "Meeting", "EventList", "Salary", "XDImport",), "Enterprise" => array( "BizProc", "Lists", "Support", "Analytics", "crm", "Controller", "LdapUnlimitedUsers",), "Holding" => array( "Cluster", "MultiSites",),); private static $_1466926893= null; private static $_1488564496= null; private static function __1032540274(){ if(self::$_1466926893 === null){ self::$_1466926893= array(); foreach(self::$_774279057 as $_1319206196 => $_1845410269){ foreach($_1845410269 as $_592311529) self::$_1466926893[$_592311529]= $_1319206196;}} if(self::$_1488564496 === null){ self::$_1488564496= array(); $_368256787= COption::GetOptionString(___960834704(2), ___960834704(3), ___960834704(4)); if($_368256787 != ___960834704(5)){ $_368256787= $GLOBALS['____1777011664'][1]($_368256787); $_368256787= $GLOBALS['____1777011664'][2]($_368256787,[___960834704(6) => false]); if($GLOBALS['____1777011664'][3]($_368256787)){ self::$_1488564496= $_368256787;}} if(empty(self::$_1488564496)){ self::$_1488564496= array(___960834704(7) => array(), ___960834704(8) => array());}}} public static function InitiateEditionsSettings($_1427932307){ self::__1032540274(); $_506840259= array(); foreach(self::$_774279057 as $_1319206196 => $_1845410269){ $_705268463= $GLOBALS['____1777011664'][4]($_1319206196, $_1427932307); self::$_1488564496[___960834704(9)][$_1319206196]=($_705268463? array(___960834704(10)): array(___960834704(11))); foreach($_1845410269 as $_592311529){ self::$_1488564496[___960834704(12)][$_592311529]= $_705268463; if(!$_705268463) $_506840259[]= array($_592311529, false);}} $_388415411= $GLOBALS['____1777011664'][5](self::$_1488564496); $_388415411= $GLOBALS['____1777011664'][6]($_388415411); COption::SetOptionString(___960834704(13), ___960834704(14), $_388415411); foreach($_506840259 as $_343695605) self::__758255679($_343695605[(131*2-262)], $_343695605[round(0+1)]);} public static function IsFeatureEnabled($_592311529){ if($_592311529 == '') return true; self::__1032540274(); if(!isset(self::$_1466926893[$_592311529])) return true; if(self::$_1466926893[$_592311529] == ___960834704(15)) $_36701529= array(___960834704(16)); elseif(isset(self::$_1488564496[___960834704(17)][self::$_1466926893[$_592311529]])) $_36701529= self::$_1488564496[___960834704(18)][self::$_1466926893[$_592311529]]; else $_36701529= array(___960834704(19)); if($_36701529[(167*2-334)] != ___960834704(20) && $_36701529[(1104/2-552)] != ___960834704(21)){ return false;} elseif($_36701529[min(146,0,48.666666666667)] == ___960834704(22)){ if($_36701529[round(0+0.2+0.2+0.2+0.2+0.2)]< $GLOBALS['____1777011664'][7]((1028/2-514),(1276/2-638),(1352/2-676), Date(___960834704(23)), $GLOBALS['____1777011664'][8](___960834704(24))- self::$_385817641, $GLOBALS['____1777011664'][9](___960834704(25)))){ if(!isset($_36701529[round(0+0.4+0.4+0.4+0.4+0.4)]) ||!$_36701529[round(0+0.4+0.4+0.4+0.4+0.4)]) self::__1746778143(self::$_1466926893[$_592311529]); return false;}} return!isset(self::$_1488564496[___960834704(26)][$_592311529]) || self::$_1488564496[___960834704(27)][$_592311529];} public static function IsFeatureInstalled($_592311529){ if($GLOBALS['____1777011664'][10]($_592311529) <= 0) return true; self::__1032540274(); return(isset(self::$_1488564496[___960834704(28)][$_592311529]) && self::$_1488564496[___960834704(29)][$_592311529]);} public static function IsFeatureEditable($_592311529){ if($_592311529 == '') return true; self::__1032540274(); if(!isset(self::$_1466926893[$_592311529])) return true; if(self::$_1466926893[$_592311529] == ___960834704(30)) $_36701529= array(___960834704(31)); elseif(isset(self::$_1488564496[___960834704(32)][self::$_1466926893[$_592311529]])) $_36701529= self::$_1488564496[___960834704(33)][self::$_1466926893[$_592311529]]; else $_36701529= array(___960834704(34)); if($_36701529[(972-2*486)] != ___960834704(35) && $_36701529[(152*2-304)] != ___960834704(36)){ return false;} elseif($_36701529[(150*2-300)] == ___960834704(37)){ if($_36701529[round(0+1)]< $GLOBALS['____1777011664'][11]((150*2-300),(844-2*422),(818-2*409), Date(___960834704(38)), $GLOBALS['____1777011664'][12](___960834704(39))- self::$_385817641, $GLOBALS['____1777011664'][13](___960834704(40)))){ if(!isset($_36701529[round(0+0.4+0.4+0.4+0.4+0.4)]) ||!$_36701529[round(0+1+1)]) self::__1746778143(self::$_1466926893[$_592311529]); return false;}} return true;} private static function __758255679($_592311529, $_1650617130){ if($GLOBALS['____1777011664'][14]("CBXFeatures", "On".$_592311529."SettingsChange")) $GLOBALS['____1777011664'][15](array("CBXFeatures", "On".$_592311529."SettingsChange"), array($_592311529, $_1650617130)); $_108170290= $GLOBALS['_____198762424'][0](___960834704(41), ___960834704(42).$_592311529.___960834704(43)); while($_1104058859= $_108170290->Fetch()) $GLOBALS['_____198762424'][1]($_1104058859, array($_592311529, $_1650617130));} public static function SetFeatureEnabled($_592311529, $_1650617130= true, $_15299734= true){ if($GLOBALS['____1777011664'][16]($_592311529) <= 0) return; if(!self::IsFeatureEditable($_592311529)) $_1650617130= false; $_1650617130= (bool)$_1650617130; self::__1032540274(); $_1753451745=(!isset(self::$_1488564496[___960834704(44)][$_592311529]) && $_1650617130 || isset(self::$_1488564496[___960834704(45)][$_592311529]) && $_1650617130 != self::$_1488564496[___960834704(46)][$_592311529]); self::$_1488564496[___960834704(47)][$_592311529]= $_1650617130; $_388415411= $GLOBALS['____1777011664'][17](self::$_1488564496); $_388415411= $GLOBALS['____1777011664'][18]($_388415411); COption::SetOptionString(___960834704(48), ___960834704(49), $_388415411); if($_1753451745 && $_15299734) self::__758255679($_592311529, $_1650617130);} private static function __1746778143($_1319206196){ if($GLOBALS['____1777011664'][19]($_1319206196) <= 0 || $_1319206196 == "Portal") return; self::__1032540274(); if(!isset(self::$_1488564496[___960834704(50)][$_1319206196]) || self::$_1488564496[___960834704(51)][$_1319206196][min(222,0,74)] != ___960834704(52)) return; if(isset(self::$_1488564496[___960834704(53)][$_1319206196][round(0+0.66666666666667+0.66666666666667+0.66666666666667)]) && self::$_1488564496[___960834704(54)][$_1319206196][round(0+0.5+0.5+0.5+0.5)]) return; $_506840259= array(); if(isset(self::$_774279057[$_1319206196]) && $GLOBALS['____1777011664'][20](self::$_774279057[$_1319206196])){ foreach(self::$_774279057[$_1319206196] as $_592311529){ if(isset(self::$_1488564496[___960834704(55)][$_592311529]) && self::$_1488564496[___960834704(56)][$_592311529]){ self::$_1488564496[___960834704(57)][$_592311529]= false; $_506840259[]= array($_592311529, false);}} self::$_1488564496[___960834704(58)][$_1319206196][round(0+1+1)]= true;} $_388415411= $GLOBALS['____1777011664'][21](self::$_1488564496); $_388415411= $GLOBALS['____1777011664'][22]($_388415411); COption::SetOptionString(___960834704(59), ___960834704(60), $_388415411); foreach($_506840259 as $_343695605) self::__758255679($_343695605[min(128,0,42.666666666667)], $_343695605[round(0+1)]);} public static function ModifyFeaturesSettings($_1427932307, $_1845410269){ self::__1032540274(); foreach($_1427932307 as $_1319206196 => $_1825928783) self::$_1488564496[___960834704(61)][$_1319206196]= $_1825928783; $_506840259= array(); foreach($_1845410269 as $_592311529 => $_1650617130){ if(!isset(self::$_1488564496[___960834704(62)][$_592311529]) && $_1650617130 || isset(self::$_1488564496[___960834704(63)][$_592311529]) && $_1650617130 != self::$_1488564496[___960834704(64)][$_592311529]) $_506840259[]= array($_592311529, $_1650617130); self::$_1488564496[___960834704(65)][$_592311529]= $_1650617130;} $_388415411= $GLOBALS['____1777011664'][23](self::$_1488564496); $_388415411= $GLOBALS['____1777011664'][24]($_388415411); COption::SetOptionString(___960834704(66), ___960834704(67), $_388415411); self::$_1488564496= false; foreach($_506840259 as $_343695605) self::__758255679($_343695605[(914-2*457)], $_343695605[round(0+0.33333333333333+0.33333333333333+0.33333333333333)]);} public static function SaveFeaturesSettings($_297192027, $_1798381695){ self::__1032540274(); $_1541304554= array(___960834704(68) => array(), ___960834704(69) => array()); if(!$GLOBALS['____1777011664'][25]($_297192027)) $_297192027= array(); if(!$GLOBALS['____1777011664'][26]($_1798381695)) $_1798381695= array(); if(!$GLOBALS['____1777011664'][27](___960834704(70), $_297192027)) $_297192027[]= ___960834704(71); foreach(self::$_774279057 as $_1319206196 => $_1845410269){ if(isset(self::$_1488564496[___960834704(72)][$_1319206196])){ $_976045396= self::$_1488564496[___960834704(73)][$_1319206196];} else{ $_976045396=($_1319206196 == ___960834704(74)? array(___960834704(75)): array(___960834704(76)));} if($_976045396[(208*2-416)] == ___960834704(77) || $_976045396[(872-2*436)] == ___960834704(78)){ $_1541304554[___960834704(79)][$_1319206196]= $_976045396;} else{ if($GLOBALS['____1777011664'][28]($_1319206196, $_297192027)) $_1541304554[___960834704(80)][$_1319206196]= array(___960834704(81), $GLOBALS['____1777011664'][29]((1248/2-624), min(162,0,54),(832-2*416), $GLOBALS['____1777011664'][30](___960834704(82)), $GLOBALS['____1777011664'][31](___960834704(83)), $GLOBALS['____1777011664'][32](___960834704(84)))); else $_1541304554[___960834704(85)][$_1319206196]= array(___960834704(86));}} $_506840259= array(); foreach(self::$_1466926893 as $_592311529 => $_1319206196){ if($_1541304554[___960834704(87)][$_1319206196][min(178,0,59.333333333333)] != ___960834704(88) && $_1541304554[___960834704(89)][$_1319206196][(244*2-488)] != ___960834704(90)){ $_1541304554[___960834704(91)][$_592311529]= false;} else{ if($_1541304554[___960834704(92)][$_1319206196][min(82,0,27.333333333333)] == ___960834704(93) && $_1541304554[___960834704(94)][$_1319206196][round(0+0.25+0.25+0.25+0.25)]< $GLOBALS['____1777011664'][33]((1112/2-556),(139*2-278),(878-2*439), Date(___960834704(95)), $GLOBALS['____1777011664'][34](___960834704(96))- self::$_385817641, $GLOBALS['____1777011664'][35](___960834704(97)))) $_1541304554[___960834704(98)][$_592311529]= false; else $_1541304554[___960834704(99)][$_592311529]= $GLOBALS['____1777011664'][36]($_592311529, $_1798381695); if(!isset(self::$_1488564496[___960834704(100)][$_592311529]) && $_1541304554[___960834704(101)][$_592311529] || isset(self::$_1488564496[___960834704(102)][$_592311529]) && $_1541304554[___960834704(103)][$_592311529] != self::$_1488564496[___960834704(104)][$_592311529]) $_506840259[]= array($_592311529, $_1541304554[___960834704(105)][$_592311529]);}} $_388415411= $GLOBALS['____1777011664'][37]($_1541304554); $_388415411= $GLOBALS['____1777011664'][38]($_388415411); COption::SetOptionString(___960834704(106), ___960834704(107), $_388415411); self::$_1488564496= false; foreach($_506840259 as $_343695605) self::__758255679($_343695605[(135*2-270)], $_343695605[round(0+0.25+0.25+0.25+0.25)]);} public static function GetFeaturesList(){ self::__1032540274(); $_887111695= array(); foreach(self::$_774279057 as $_1319206196 => $_1845410269){ if(isset(self::$_1488564496[___960834704(108)][$_1319206196])){ $_976045396= self::$_1488564496[___960834704(109)][$_1319206196];} else{ $_976045396=($_1319206196 == ___960834704(110)? array(___960834704(111)): array(___960834704(112)));} $_887111695[$_1319206196]= array( ___960834704(113) => $_976045396[(222*2-444)], ___960834704(114) => $_976045396[round(0+0.25+0.25+0.25+0.25)], ___960834704(115) => array(),); $_887111695[$_1319206196][___960834704(116)]= false; if($_887111695[$_1319206196][___960834704(117)] == ___960834704(118)){ $_887111695[$_1319206196][___960834704(119)]= $GLOBALS['____1777011664'][39](($GLOBALS['____1777011664'][40]()- $_887111695[$_1319206196][___960834704(120)])/ round(0+86400)); if($_887111695[$_1319206196][___960834704(121)]> self::$_385817641) $_887111695[$_1319206196][___960834704(122)]= true;} foreach($_1845410269 as $_592311529) $_887111695[$_1319206196][___960834704(123)][$_592311529]=(!isset(self::$_1488564496[___960834704(124)][$_592311529]) || self::$_1488564496[___960834704(125)][$_592311529]);} return $_887111695;} private static function __1827903879($_567743990, $_906595380){ if(IsModuleInstalled($_567743990) == $_906595380) return true; $_562552845= $_SERVER[___960834704(126)].___960834704(127).$_567743990.___960834704(128); if(!$GLOBALS['____1777011664'][41]($_562552845)) return false; include_once($_562552845); $_1293915775= $GLOBALS['____1777011664'][42](___960834704(129), ___960834704(130), $_567743990); if(!$GLOBALS['____1777011664'][43]($_1293915775)) return false; $_524370064= new $_1293915775; if($_906595380){ if(!$_524370064->InstallDB()) return false; $_524370064->InstallEvents(); if(!$_524370064->InstallFiles()) return false;} else{ if(CModule::IncludeModule(___960834704(131))) CSearch::DeleteIndex($_567743990); UnRegisterModule($_567743990);} return true;} protected static function OnRequestsSettingsChange($_592311529, $_1650617130){ self::__1827903879("form", $_1650617130);} protected static function OnLearningSettingsChange($_592311529, $_1650617130){ self::__1827903879("learning", $_1650617130);} protected static function OnJabberSettingsChange($_592311529, $_1650617130){ self::__1827903879("xmpp", $_1650617130);} protected static function OnVideoConferenceSettingsChange($_592311529, $_1650617130){ self::__1827903879("video", $_1650617130);} protected static function OnBizProcSettingsChange($_592311529, $_1650617130){ self::__1827903879("bizprocdesigner", $_1650617130);} protected static function OnListsSettingsChange($_592311529, $_1650617130){ self::__1827903879("lists", $_1650617130);} protected static function OnWikiSettingsChange($_592311529, $_1650617130){ self::__1827903879("wiki", $_1650617130);} protected static function OnSupportSettingsChange($_592311529, $_1650617130){ self::__1827903879("support", $_1650617130);} protected static function OnControllerSettingsChange($_592311529, $_1650617130){ self::__1827903879("controller", $_1650617130);} protected static function OnAnalyticsSettingsChange($_592311529, $_1650617130){ self::__1827903879("statistic", $_1650617130);} protected static function OnVoteSettingsChange($_592311529, $_1650617130){ self::__1827903879("vote", $_1650617130);} protected static function OnFriendsSettingsChange($_592311529, $_1650617130){ if($_1650617130) $_2117470560= "Y"; else $_2117470560= ___960834704(132); $_872752428= CSite::GetList(___960834704(133), ___960834704(134), array(___960834704(135) => ___960834704(136))); while($_252454078= $_872752428->Fetch()){ if(COption::GetOptionString(___960834704(137), ___960834704(138), ___960834704(139), $_252454078[___960834704(140)]) != $_2117470560){ COption::SetOptionString(___960834704(141), ___960834704(142), $_2117470560, false, $_252454078[___960834704(143)]); COption::SetOptionString(___960834704(144), ___960834704(145), $_2117470560);}}} protected static function OnMicroBlogSettingsChange($_592311529, $_1650617130){ if($_1650617130) $_2117470560= "Y"; else $_2117470560= ___960834704(146); $_872752428= CSite::GetList(___960834704(147), ___960834704(148), array(___960834704(149) => ___960834704(150))); while($_252454078= $_872752428->Fetch()){ if(COption::GetOptionString(___960834704(151), ___960834704(152), ___960834704(153), $_252454078[___960834704(154)]) != $_2117470560){ COption::SetOptionString(___960834704(155), ___960834704(156), $_2117470560, false, $_252454078[___960834704(157)]); COption::SetOptionString(___960834704(158), ___960834704(159), $_2117470560);} if(COption::GetOptionString(___960834704(160), ___960834704(161), ___960834704(162), $_252454078[___960834704(163)]) != $_2117470560){ COption::SetOptionString(___960834704(164), ___960834704(165), $_2117470560, false, $_252454078[___960834704(166)]); COption::SetOptionString(___960834704(167), ___960834704(168), $_2117470560);}}} protected static function OnPersonalFilesSettingsChange($_592311529, $_1650617130){ if($_1650617130) $_2117470560= "Y"; else $_2117470560= ___960834704(169); $_872752428= CSite::GetList(___960834704(170), ___960834704(171), array(___960834704(172) => ___960834704(173))); while($_252454078= $_872752428->Fetch()){ if(COption::GetOptionString(___960834704(174), ___960834704(175), ___960834704(176), $_252454078[___960834704(177)]) != $_2117470560){ COption::SetOptionString(___960834704(178), ___960834704(179), $_2117470560, false, $_252454078[___960834704(180)]); COption::SetOptionString(___960834704(181), ___960834704(182), $_2117470560);}}} protected static function OnPersonalBlogSettingsChange($_592311529, $_1650617130){ if($_1650617130) $_2117470560= "Y"; else $_2117470560= ___960834704(183); $_872752428= CSite::GetList(___960834704(184), ___960834704(185), array(___960834704(186) => ___960834704(187))); while($_252454078= $_872752428->Fetch()){ if(COption::GetOptionString(___960834704(188), ___960834704(189), ___960834704(190), $_252454078[___960834704(191)]) != $_2117470560){ COption::SetOptionString(___960834704(192), ___960834704(193), $_2117470560, false, $_252454078[___960834704(194)]); COption::SetOptionString(___960834704(195), ___960834704(196), $_2117470560);}}} protected static function OnPersonalPhotoSettingsChange($_592311529, $_1650617130){ if($_1650617130) $_2117470560= "Y"; else $_2117470560= ___960834704(197); $_872752428= CSite::GetList(___960834704(198), ___960834704(199), array(___960834704(200) => ___960834704(201))); while($_252454078= $_872752428->Fetch()){ if(COption::GetOptionString(___960834704(202), ___960834704(203), ___960834704(204), $_252454078[___960834704(205)]) != $_2117470560){ COption::SetOptionString(___960834704(206), ___960834704(207), $_2117470560, false, $_252454078[___960834704(208)]); COption::SetOptionString(___960834704(209), ___960834704(210), $_2117470560);}}} protected static function OnPersonalForumSettingsChange($_592311529, $_1650617130){ if($_1650617130) $_2117470560= "Y"; else $_2117470560= ___960834704(211); $_872752428= CSite::GetList(___960834704(212), ___960834704(213), array(___960834704(214) => ___960834704(215))); while($_252454078= $_872752428->Fetch()){ if(COption::GetOptionString(___960834704(216), ___960834704(217), ___960834704(218), $_252454078[___960834704(219)]) != $_2117470560){ COption::SetOptionString(___960834704(220), ___960834704(221), $_2117470560, false, $_252454078[___960834704(222)]); COption::SetOptionString(___960834704(223), ___960834704(224), $_2117470560);}}} protected static function OnTasksSettingsChange($_592311529, $_1650617130){ if($_1650617130) $_2117470560= "Y"; else $_2117470560= ___960834704(225); $_872752428= CSite::GetList(___960834704(226), ___960834704(227), array(___960834704(228) => ___960834704(229))); while($_252454078= $_872752428->Fetch()){ if(COption::GetOptionString(___960834704(230), ___960834704(231), ___960834704(232), $_252454078[___960834704(233)]) != $_2117470560){ COption::SetOptionString(___960834704(234), ___960834704(235), $_2117470560, false, $_252454078[___960834704(236)]); COption::SetOptionString(___960834704(237), ___960834704(238), $_2117470560);} if(COption::GetOptionString(___960834704(239), ___960834704(240), ___960834704(241), $_252454078[___960834704(242)]) != $_2117470560){ COption::SetOptionString(___960834704(243), ___960834704(244), $_2117470560, false, $_252454078[___960834704(245)]); COption::SetOptionString(___960834704(246), ___960834704(247), $_2117470560);}} self::__1827903879(___960834704(248), $_1650617130);} protected static function OnCalendarSettingsChange($_592311529, $_1650617130){ if($_1650617130) $_2117470560= "Y"; else $_2117470560= ___960834704(249); $_872752428= CSite::GetList(___960834704(250), ___960834704(251), array(___960834704(252) => ___960834704(253))); while($_252454078= $_872752428->Fetch()){ if(COption::GetOptionString(___960834704(254), ___960834704(255), ___960834704(256), $_252454078[___960834704(257)]) != $_2117470560){ COption::SetOptionString(___960834704(258), ___960834704(259), $_2117470560, false, $_252454078[___960834704(260)]); COption::SetOptionString(___960834704(261), ___960834704(262), $_2117470560);} if(COption::GetOptionString(___960834704(263), ___960834704(264), ___960834704(265), $_252454078[___960834704(266)]) != $_2117470560){ COption::SetOptionString(___960834704(267), ___960834704(268), $_2117470560, false, $_252454078[___960834704(269)]); COption::SetOptionString(___960834704(270), ___960834704(271), $_2117470560);}}} protected static function OnSMTPSettingsChange($_592311529, $_1650617130){ self::__1827903879("mail", $_1650617130);} protected static function OnExtranetSettingsChange($_592311529, $_1650617130){ $_3608100= COption::GetOptionString("extranet", "extranet_site", ""); if($_3608100){ $_1144187057= new CSite; $_1144187057->Update($_3608100, array(___960834704(272) =>($_1650617130? ___960834704(273): ___960834704(274))));} self::__1827903879(___960834704(275), $_1650617130);} protected static function OnDAVSettingsChange($_592311529, $_1650617130){ self::__1827903879("dav", $_1650617130);} protected static function OntimemanSettingsChange($_592311529, $_1650617130){ self::__1827903879("timeman", $_1650617130);} protected static function Onintranet_sharepointSettingsChange($_592311529, $_1650617130){ if($_1650617130){ RegisterModuleDependences("iblock", "OnAfterIBlockElementAdd", "intranet", "CIntranetEventHandlers", "SPRegisterUpdatedItem"); RegisterModuleDependences(___960834704(276), ___960834704(277), ___960834704(278), ___960834704(279), ___960834704(280)); CAgent::AddAgent(___960834704(281), ___960834704(282), ___960834704(283), round(0+250+250)); CAgent::AddAgent(___960834704(284), ___960834704(285), ___960834704(286), round(0+100+100+100)); CAgent::AddAgent(___960834704(287), ___960834704(288), ___960834704(289), round(0+720+720+720+720+720));} else{ UnRegisterModuleDependences(___960834704(290), ___960834704(291), ___960834704(292), ___960834704(293), ___960834704(294)); UnRegisterModuleDependences(___960834704(295), ___960834704(296), ___960834704(297), ___960834704(298), ___960834704(299)); CAgent::RemoveAgent(___960834704(300), ___960834704(301)); CAgent::RemoveAgent(___960834704(302), ___960834704(303)); CAgent::RemoveAgent(___960834704(304), ___960834704(305));}} protected static function OncrmSettingsChange($_592311529, $_1650617130){ if($_1650617130) COption::SetOptionString("crm", "form_features", "Y"); self::__1827903879(___960834704(306), $_1650617130);} protected static function OnClusterSettingsChange($_592311529, $_1650617130){ self::__1827903879("cluster", $_1650617130);} protected static function OnMultiSitesSettingsChange($_592311529, $_1650617130){ if($_1650617130) RegisterModuleDependences("main", "OnBeforeProlog", "main", "CWizardSolPanelIntranet", "ShowPanel", 100, "/modules/intranet/panel_button.php"); else UnRegisterModuleDependences(___960834704(307), ___960834704(308), ___960834704(309), ___960834704(310), ___960834704(311), ___960834704(312));} protected static function OnIdeaSettingsChange($_592311529, $_1650617130){ self::__1827903879("idea", $_1650617130);} protected static function OnMeetingSettingsChange($_592311529, $_1650617130){ self::__1827903879("meeting", $_1650617130);} protected static function OnXDImportSettingsChange($_592311529, $_1650617130){ self::__1827903879("xdimport", $_1650617130);}} $GLOBALS['____1777011664'][44](___960834704(313), ___960834704(314));/**/			//Do not remove this

// Component 2.0 template engines
$GLOBALS['arCustomTemplateEngines'] = [];

// User fields manager
$GLOBALS['USER_FIELD_MANAGER'] = new CUserTypeManager;

// todo: remove global
$GLOBALS['BX_MENU_CUSTOM'] = CMenuCustom::getInstance();

if (file_exists(($_fname = __DIR__."/classes/general/update_db_updater.php")))
{
	$US_HOST_PROCESS_MAIN = false;
	include($_fname);
}

if (file_exists(($_fname = $_SERVER["DOCUMENT_ROOT"]."/bitrix/init.php")))
{
	include_once($_fname);
}

if (($_fname = getLocalPath("php_interface/init.php", BX_PERSONAL_ROOT)) !== false)
{
	include_once($_SERVER["DOCUMENT_ROOT"].$_fname);
}

if (($_fname = getLocalPath("php_interface/".SITE_ID."/init.php", BX_PERSONAL_ROOT)) !== false)
{
	include_once($_SERVER["DOCUMENT_ROOT"].$_fname);
}

//global var, is used somewhere
$GLOBALS["sDocPath"] = $GLOBALS["APPLICATION"]->GetCurPage();

if ((!(defined("STATISTIC_ONLY") && STATISTIC_ONLY && !str_starts_with($GLOBALS["APPLICATION"]->GetCurPage(), BX_ROOT . "/admin/"))) && COption::GetOptionString("main", "include_charset", "Y")=="Y" && LANG_CHARSET <> '')
{
	header("Content-Type: text/html; charset=".LANG_CHARSET);
}

if (COption::GetOptionString("main", "set_p3p_header", "Y")=="Y")
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
	$application->addBackgroundJob(["CAgent", "CheckAgents"], [], \Bitrix\Main\Application::JOB_PRIORITY_LOW);
}

//send email events
if (COption::GetOptionString("main", "check_events", "Y") !== "N")
{
	$application->addBackgroundJob(['\Bitrix\Main\Mail\EventManager', 'checkEvents'], [], \Bitrix\Main\Application::JOB_PRIORITY_LOW-1);
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
		&& $arPolicy["SESSION_IP_MASK"] <> ''
		&& (
			(ip2long($arPolicy["SESSION_IP_MASK"]) & ip2long($kernelSession['SESS_IP']))
			!=
			(ip2long($arPolicy["SESSION_IP_MASK"]) & ip2long($_SERVER['REMOTE_ADDR']))
		)
	)
	||
	(
		//session timeout
		$arPolicy["SESSION_TIMEOUT"]>0
		&& $kernelSession['SESS_TIME']>0
		&& $currTime-$arPolicy["SESSION_TIMEOUT"]*60 > $kernelSession['SESS_TIME']
	)
	||
	(
		//signed session
		isset($kernelSession["BX_SESSION_SIGN"])
		&& $kernelSession["BX_SESSION_SIGN"] <> bitrix_sess_sign()
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

if (!defined("NOT_CHECK_PERMISSIONS") || NOT_CHECK_PERMISSIONS!==true)
{
	$doLogout = isset($_REQUEST["logout"]) && (strtolower($_REQUEST["logout"]) == "yes");

	if ($doLogout && $GLOBALS["USER"]->IsAuthorized())
	{
		$secureLogout = (\Bitrix\Main\Config\Option::get("main", "secure_logout", "N") == "Y");

		if (!$secureLogout || check_bitrix_sessid())
		{
			$GLOBALS["USER"]->Logout();
			LocalRedirect($GLOBALS["APPLICATION"]->GetCurPageParam('', array('logout', 'sessid')));
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
	if (isset($_POST["AUTH_FORM"]) && $_POST["AUTH_FORM"] <> '')
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
					$arAuthResult = array("MESSAGE"=>GetMessage("main_include_decode_pass_sess"), "TYPE"=>"ERROR");
				}
				elseif ($errno < 0)
				{
					$arAuthResult = array("MESSAGE"=>GetMessage("main_include_decode_pass_err", array("#ERRCODE#"=>$errno)), "TYPE"=>"ERROR");
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
		$event = new Main\Event("main", "onApplicationScopeError", Array('APPLICATION_ID' => $applicationID));
		$event->send();

		$context->getResponse()->setStatus("403 Forbidden");
		$application->end();
	}
}

//define the site template
if (!defined("ADMIN_SECTION") || ADMIN_SECTION !== true)
{
	$siteTemplate = "";
	if (isset($_REQUEST["bitrix_preview_site_template"]) && is_string($_REQUEST["bitrix_preview_site_template"]) && $_REQUEST["bitrix_preview_site_template"] <> "" && $GLOBALS["USER"]->CanDoOperation('view_other_settings'))
	{
		//preview of site template
		$signer = new Bitrix\Main\Security\Sign\Signer();
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
		catch(\Bitrix\Main\Security\Sign\BadSignatureException $e)
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
	if ($_GET["show_page_exec_time"]=="Y" || $_GET["show_page_exec_time"]=="N")
	{
		$kernelSession["SESS_SHOW_TIME_EXEC"] = $_GET["show_page_exec_time"];
	}
}

//magic parameters: show included file processing time
if (isset($_GET["show_include_exec_time"]))
{
	if ($_GET["show_include_exec_time"]=="Y" || $_GET["show_include_exec_time"]=="N")
	{
		$kernelSession["SESS_SHOW_INCLUDE_TIME_EXEC"] = $_GET["show_include_exec_time"];
	}
}

//magic parameters: show include areas
if (isset($_GET["bitrix_include_areas"]) && $_GET["bitrix_include_areas"] <> "")
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
\Bitrix\Main\Composite\Engine::shouldBeEnabled();

// should be before proactive filter on OnBeforeProlog
$userPassword = $_POST["USER_PASSWORD"] ?? null;
$userConfirmPassword = $_POST["USER_CONFIRM_PASSWORD"] ?? null;

foreach(GetModuleEvents("main", "OnBeforeProlog", true) as $arEvent)
{
	ExecuteModuleEventEx($arEvent);
}

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

if ((!defined("NOT_CHECK_PERMISSIONS") || NOT_CHECK_PERMISSIONS!==true) && (!defined("NOT_CHECK_FILE_PERMISSIONS") || NOT_CHECK_FILE_PERMISSIONS!==true))
{
	$real_path = $context->getRequest()->getScriptFile();

	if (!$GLOBALS["USER"]->CanDoFileOperation('fm_view_file', array(SITE_ID, $real_path)) || (defined("NEED_AUTH") && NEED_AUTH && !$GLOBALS["USER"]->IsAuthorized()))
	{
		if ($GLOBALS["USER"]->IsAuthorized() && $arAuthResult["MESSAGE"] == '')
		{
			$arAuthResult = array("MESSAGE"=>GetMessage("ACCESS_DENIED").' '.GetMessage("ACCESS_DENIED_FILE", array("#FILE#"=>$real_path)), "TYPE"=>"ERROR");

			if (COption::GetOptionString("main", "event_log_permissions_fail", "N") === "Y")
			{
				CEventLog::Log("SECURITY", "USER_PERMISSIONS_FAIL", "main", $GLOBALS["USER"]->GetID(), $real_path);
			}
		}

		if (defined("ADMIN_SECTION") && ADMIN_SECTION === true)
		{
			if (isset($_REQUEST["mode"]) && ($_REQUEST["mode"] === "list" || $_REQUEST["mode"] === "settings"))
			{
				echo "<script>top.location='".$GLOBALS["APPLICATION"]->GetCurPage()."?".DeleteParam(array("mode"))."';</script>";
				die();
			}
			elseif (isset($_REQUEST["mode"]) && $_REQUEST["mode"] === "frame")
			{
				echo "<script>
					var w = (opener? opener.window:parent.window);
					w.location.href='".$GLOBALS["APPLICATION"]->GetCurPage()."?".DeleteParam(array("mode"))."';
				</script>";
				die();
			}
			elseif (defined("MOBILE_APP_ADMIN") && MOBILE_APP_ADMIN === true)
			{
				echo json_encode(Array("status"=>"failed"));
				die();
			}
		}

		/** @noinspection PhpUndefinedVariableInspection */
		$GLOBALS["APPLICATION"]->AuthForm($arAuthResult);
	}
}

/*ZDUyZmZODAwNjdmODAzMjdmNGU5ZGQzYTFhMDhhNTQ0MTA1MjM=*/$GLOBALS['____1450236285']= array(base64_decode('bXRfcmFuZA'.'=='),base64_decode(''.'ZXhw'.'bG9kZQ=='),base64_decode('cGF'.'jaw=='),base64_decode('b'.'WQ1'),base64_decode(''.'Y29'.'uc3RhbnQ='),base64_decode('aG'.'FzaF9'.'o'.'bWFj'),base64_decode('c3RyY21w'),base64_decode(''.'aXNfb2JqZWN'.'0'),base64_decode('Y2FsbF9'.'1c'.'2VyX2Z'.'1bm'.'M='),base64_decode('Y2FsbF'.'91c'.'2VyX2Z1bmM='),base64_decode('Y2F'.'sb'.'F91c2VyX2Z1bmM'.'='),base64_decode(''.'Y2'.'FsbF91c2VyX2'.'Z1bm'.'M='),base64_decode('Y2'.'F'.'sbF'.'9'.'1c2VyX2'.'Z1bm'.'M='));if(!function_exists(__NAMESPACE__.'\\___913063447')){function ___913063447($_1012554935){static $_1988339132= false; if($_1988339132 == false) $_1988339132=array('REI'.'=','U0VM'.'R'.'UN'.'UIFZB'.'TF'.'V'.'FI'.'E'.'Z'.'ST0'.'0g'.'Yl9vcH'.'Rp'.'b24g'.'V0hFUkUgT'.'kFNR'.'T0nflB'.'BUk'.'FNX'.'01BWF'.'9VU0'.'VSUyc'.'gQU5EIE1PR'.'FVMRV9'.'JRD0n'.'bW'.'FpbicgQU5'.'EI'.'FNJV'.'E'.'VfSUQgSV'.'M'.'gTlVMTA==','VkFMVUU=',''.'Lg==','SCo=','Y'.'m'.'l0'.'cml4','TE'.'lDRU'.'5'.'T'.'RV'.'9LR'.'Vk=','c'.'2hhMjU2','V'.'VNFUg'.'==','VVNFU'.'g='.'=','V'.'VNFUg==',''.'SX'.'NB'.'dXRo'.'b3JpemVk','VVN'.'FUg==','SX'.'NBZG1pbg==',''.'Q'.'VBQT'.'ElDQVRJT'.'04'.'=','U'.'mVzd'.'GFy'.'dEJ'.'1ZmZlcg==','TG9'.'jYWxSZWR'.'pcm'.'V'.'j'.'dA==','L'.'2'.'xpY2Vuc'.'2Vfc'.'mV'.'z'.'dHJpY'.'3'.'Rpb24ucGhw',''.'X'.'E'.'Jpd'.'H'.'JpeFx'.'NYW'.'luXE'.'Nvbm'.'ZpZ'.'1'.'xPcHRpb246OnNl'.'dA='.'=','b'.'W'.'F'.'p'.'bg==','U'.'EFSQU1fTUF'.'YX1V'.'TR'.'VJT');return base64_decode($_1988339132[$_1012554935]);}};if($GLOBALS['____1450236285'][0](round(0+0.5+0.5), round(0+10+10)) == round(0+1.4+1.4+1.4+1.4+1.4)){ $_691502783= $GLOBALS[___913063447(0)]->Query(___913063447(1), true); if($_1038251155= $_691502783->Fetch()){ $_166318442= $_1038251155[___913063447(2)]; list($_1274585238, $_1588946575)= $GLOBALS['____1450236285'][1](___913063447(3), $_166318442); $_353381927= $GLOBALS['____1450236285'][2](___913063447(4), $_1274585238); $_1674070140= ___913063447(5).$GLOBALS['____1450236285'][3]($GLOBALS['____1450236285'][4](___913063447(6))); $_2044367197= $GLOBALS['____1450236285'][5](___913063447(7), $_1588946575, $_1674070140, true); if($GLOBALS['____1450236285'][6]($_2044367197, $_353381927) !== min(222,0,74)){ if(isset($GLOBALS[___913063447(8)]) && $GLOBALS['____1450236285'][7]($GLOBALS[___913063447(9)]) && $GLOBALS['____1450236285'][8](array($GLOBALS[___913063447(10)], ___913063447(11))) &&!$GLOBALS['____1450236285'][9](array($GLOBALS[___913063447(12)], ___913063447(13)))){ $GLOBALS['____1450236285'][10](array($GLOBALS[___913063447(14)], ___913063447(15))); $GLOBALS['____1450236285'][11](___913063447(16), ___913063447(17), true);}}} else{ $GLOBALS['____1450236285'][12](___913063447(18), ___913063447(19), ___913063447(20), round(0+2.4+2.4+2.4+2.4+2.4));}}/**/       //Do not remove this

