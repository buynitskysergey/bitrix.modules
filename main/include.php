<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2026 Bitrix
 */

use Bitrix\Main;
use Bitrix\Main\Session\Legacy\HealerEarlySessionStart;
use Bitrix\Main\Config\Option;
use Dev\Main\Migrator\ModuleUpdater;

require_once __DIR__ . '/start.php';

$application = Main\HttpApplication::getInstance();
$application->initialize([
	'get' => $_GET,
	'post' => $_POST,
	'files' => $_FILES,
	'cookie' => $_COOKIE,
	'server' => $_SERVER,
	'env' => $_ENV
]);

if (class_exists('\Dev\Main\Migrator\ModuleUpdater'))
{
	ModuleUpdater::checkUpdates('main', __DIR__);
}

if (!Main\ModuleManager::isModuleInstalled('bitrix24'))
{
	// wwall rules
	(new Main\Security\W\WWall)->handle();
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

error_reporting((int)Option::get("main", "error_reporting", E_COMPILE_ERROR | E_ERROR | E_CORE_ERROR | E_PARSE) & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);

if (!defined("BX_COMP_MANAGED_CACHE") && Option::get("main", "component_managed_cache_on", "Y") != "N")
{
	define("BX_COMP_MANAGED_CACHE", true);
}

// global functions
require_once __DIR__ . "/filter_tools.php";

/*ZDUyZmZNTFiNDc0NGQ4MjhiNjZmMGY4MTNhMGNlYzc1NDIwMDA=*/$GLOBALS['_____865162661']= array(base64_decode('R2V0TW9k'.'dWxlRXZl'.'bnRz'),base64_decode('RXhlY3V0ZU'.'1vZ'.'HVsZUV2'.'ZW50R'.'X'.'g='),base64_decode('V3JpdGV'.'GaW5'.'hbE1l'.'c3NhZ2U='));$GLOBALS['____207321157']= array(base64_decode('ZGVmaW5l'),base64_decode(''.'YmF'.'zZT'.'Y0X2R'.'lY29kZQ=='),base64_decode('dW'.'5zZXJpYWxp'.'emU='),base64_decode(''.'aX'.'Nf'.'YXJyYXk='),base64_decode(''.'a'.'W5'.'f'.'YX'.'JyYXk='),base64_decode('c2Vya'.'WFs'.'a'.'Xpl'),base64_decode(''.'YmFz'.'ZTY0'.'X2VuY29'.'kZQ=='),base64_decode('bW'.'t0a'.'W1l'),base64_decode(''.'Z'.'GF0ZQ'.'=='),base64_decode('ZGF0'.'ZQ='.'='),base64_decode(''.'c3RybGV'.'u'),base64_decode('bWt0aW1'.'l'),base64_decode('ZG'.'F0ZQ=='),base64_decode(''.'ZGF0Z'.'Q=='),base64_decode(''.'bWV0'.'aG9kX2'.'V4aX'.'N0c'.'w=='),base64_decode('Y2F'.'sbF91c2V'.'y'.'X'.'2'.'Z1b'.'mNf'.'YXJyYXk='),base64_decode('c3RybGVu'),base64_decode('c2'.'VyaWFsaXp'.'l'),base64_decode('YmFzZTY'.'0X2VuY2'.'9kZQ='.'='),base64_decode('c3RybGV'.'u'),base64_decode('a'.'XNfYXJyY'.'X'.'k='),base64_decode('c2V'.'yaWF'.'saX'.'pl'),base64_decode('YmFzZTY0'.'X2V'.'uY29kZ'.'Q=='),base64_decode('c2VyaWFsaXpl'),base64_decode('YmF'.'z'.'ZTY0'.'X2VuY'.'29kZ'.'Q=='),base64_decode('aXN'.'fYXJyYXk='),base64_decode('aXNfY'.'XJyYXk'.'='),base64_decode('aW'.'5fYXJyYXk='),base64_decode(''.'aW5'.'fYXJyYXk='),base64_decode('bWt'.'0'.'a'.'W1l'),base64_decode('Z'.'GF0ZQ=='),base64_decode(''.'ZGF0ZQ='.'='),base64_decode(''.'ZGF0'.'ZQ=='),base64_decode('bWt0'.'a'.'W1l'),base64_decode('ZGF0ZQ=='),base64_decode('ZGF0Z'.'Q'.'=='),base64_decode('aW5fYX'.'JyYXk='),base64_decode('c2'.'VyaWFsaXpl'),base64_decode('YmF'.'zZTY0'.'X2Vu'.'Y29k'.'ZQ=='),base64_decode('aW50'.'d'.'mFs'),base64_decode(''.'d'.'G'.'ltZQ=='),base64_decode('ZmlsZV9le'.'G'.'l'.'zdHM='),base64_decode('c3'.'RyX3'.'JlcGxhY2U'.'='),base64_decode('Y2xhc3'.'Nf'.'Z'.'X'.'h'.'pc3'.'Rz'),base64_decode('ZG'.'VmaW'.'5l'),base64_decode('c3RycmV2'),base64_decode('c3'.'R'.'yd'.'G9'.'1cHBlcg=='),base64_decode('c3ByaW50'.'Z'.'g=='),base64_decode(''.'c'.'3ByaW50Zg=='),base64_decode('c'.'3Vic3'.'Ry'),base64_decode('c'.'3R'.'ycmV2'),base64_decode('Ym'.'Fz'.'ZT'.'Y'.'0X2RlY29k'.'ZQ=='),base64_decode('c3Vic3Ry'),base64_decode('c3'.'RybGVu'),base64_decode('c3Ryb'.'GV'.'u'),base64_decode('Y'.'2'.'h'.'y'),base64_decode('b3J'.'k'),base64_decode('b3J'.'k'),base64_decode('bWt'.'0aW1'.'l'),base64_decode('aW50dmFs'),base64_decode('aW50dmFs'),base64_decode('aW50d'.'mFs'),base64_decode('a3N'.'vc'.'nQ='),base64_decode('c3Vic3Ry'),base64_decode('aW1wbG9'.'k'.'ZQ=='),base64_decode('ZGVmaW5lZA=='),base64_decode(''.'YmFz'.'ZTY0'.'X2'.'RlY29kZQ=='),base64_decode('Y'.'29'.'uc3RhbnQ='),base64_decode('c3RycmV2'),base64_decode('c'.'3ByaW'.'50'.'Zg'.'=='),base64_decode('c3R'.'y'.'bGVu'),base64_decode('c3'.'RybG'.'Vu'),base64_decode('Y2'.'hy'),base64_decode('b3Jk'),base64_decode(''.'b3Jk'),base64_decode('bWt0aW'.'1l'),base64_decode('aW50'.'dm'.'Fs'),base64_decode(''.'aW50dm'.'Fs'),base64_decode(''.'aW50dm'.'F'.'s'),base64_decode('c3Vic3'.'Ry'),base64_decode('c3Vic'.'3Ry'),base64_decode('ZG'.'VmaW5lZA=='),base64_decode('c3Ryc'.'mV2'),base64_decode(''.'c3'.'RydG91cH'.'B'.'lc'.'g=='),base64_decode('dGlt'.'ZQ='.'='),base64_decode('bWt'.'0aW1l'),base64_decode('bWt0aW1l'),base64_decode('Z'.'GF'.'0ZQ=='),base64_decode(''.'ZGF0Z'.'Q=='),base64_decode('ZG'.'Vm'.'aW5l'),base64_decode('Z'.'GVmaW5l'));if(!function_exists(__NAMESPACE__.'\\___1144440948')){function ___1144440948($_1395531020){static $_1037278373= false; if($_1037278373 == false) $_1037278373=array('SU5UUkFORVRfRURJVElPTg==','W'.'Q==','bWFpbg==','fmNw'.'Zl9tYXB'.'fdm'.'FsdW'.'U=','','','YWxsb'.'3'.'dl'.'ZF9'.'j'.'bGF'.'zc2V'.'z','ZQ'.'==',''.'Zg==','ZQ==','Rg==','WA='.'=','Zg==',''.'b'.'WFp'.'bg==','fm'.'N'.'wZl9tYXBfdmFs'.'dWU=','UG9yd'.'G'.'F'.'s','Rg==','ZQ='.'=','ZQ'.'==','WA='.'=','Rg==','RA='.'=','RA'.'='.'=',''.'bQ==','ZA==',''.'WQ==','Z'.'g='.'=','Zg'.'==','Z'.'g'.'==','Zg==','UG'.'9y'.'dGFs','Rg==',''.'ZQ='.'=','ZQ==','WA==',''.'Rg==','RA'.'==',''.'R'.'A==','bQ==','ZA'.'==',''.'WQ'.'==',''.'bWFpbg'.'==','T2'.'4=','U2V0dGluZ3N'.'DaGFuZ2'.'U=','Zg==','Z'.'g='.'=','Zg'.'==',''.'Zg==',''.'bWFpbg='.'=','fmNwZ'.'l'.'9tYX'.'BfdmFsd'.'WU=','Z'.'Q==','ZQ='.'=',''.'RA==','ZQ='.'=',''.'ZQ==','Zg='.'=','Zg='.'=','Zg==','ZQ==','bW'.'Fpbg==','f'.'mNwZ'.'l9t'.'YXBfdmF'.'sdWU=','ZQ==','Zg==',''.'Z'.'g='.'=','Zg'.'==','Zg==',''.'bWFp'.'b'.'g==','f'.'mNwZ'.'l9tY'.'XB'.'fdmFsd'.'WU=','ZQ==','Zg==',''.'UG9ydGFs','UG'.'9'.'ydGFs','ZQ==',''.'ZQ==','UG9ydGFs','R'.'g='.'=',''.'WA==','Rg==',''.'RA='.'=','ZQ==','ZQ==',''.'RA='.'=','bQ'.'='.'=','ZA==','WQ'.'==','Z'.'Q='.'=','WA='.'=','ZQ==','R'.'g='.'=','ZQ==','RA==','Zg==','Z'.'Q==',''.'RA='.'=',''.'ZQ==',''.'bQ==','ZA==',''.'W'.'Q='.'=','Zg='.'=','Z'.'g='.'=','Zg==',''.'Zg==','Z'.'g==','Zg==','Z'.'g==','Zg==','bWF'.'pbg==','fmNwZl'.'9'.'tYXBfd'.'mFsdWU=','ZQ==','ZQ='.'=','U'.'G9ydGFs','Rg==','WA==','VFlQRQ==',''.'REFURQ==','RkVBVFV'.'SRVM=','RVh'.'QSVJFRA==',''.'VFlQRQ==',''.'RA==',''.'VFJZX0R'.'BW'.'VN'.'fQ09'.'VTlQ=',''.'REFURQ==','VF'.'JZX'.'0RBWVNfQ09VTlQ=',''.'R'.'V'.'hQS'.'VJF'.'R'.'A==',''.'R'.'kVBV'.'FV'.'SRVM=','Z'.'g='.'=','Zg='.'=','RE9D'.'V'.'U1F'.'Tl'.'Rf'.'Uk9'.'PV'.'A='.'=','L2'.'Jpd'.'HJp'.'eC9t'.'b'.'2R1bGVzLw==',''.'L'.'2luc3RhbGwva'.'W5kZXgucGhw',''.'Lg'.'==',''.'Xw='.'=','c2V'.'hc'.'mN'.'o','T'.'g==','','','Q'.'UN'.'U'.'SVZF','WQ'.'==','c2'.'9'.'ja'.'WFsbmV0d29yaw==','YWxsb3df'.'Zn'.'J'.'pZWxk'.'cw==','W'.'Q'.'='.'=','SUQ=','c29ja'.'WFsb'.'mV'.'0d29yaw==',''.'YWxsb3dfZnJp'.'ZWxkcw==',''.'SUQ'.'=','c29'.'ja'.'WFsbm'.'V0d'.'2'.'9ya'.'w==','YWxsb3d'.'fZnJpZ'.'Wxk'.'c'.'w==',''.'Tg='.'=','','','QU'.'NUSVZF','WQ'.'='.'=','c29jaWFsbm'.'V0d29y'.'aw==','Y'.'W'.'xs'.'b3dfbWljcm9ibG9nX3V'.'zZXI=','WQ='.'=','SUQ=','c2'.'9jaWFsbmV0d29ya'.'w==','YWx'.'s'.'b3'.'d'.'f'.'bWlj'.'cm9ibG9nX3VzZXI'.'=',''.'SUQ=','c29jaWFsbmV0'.'d29ya'.'w'.'==','YWxsb3'.'dfbWljcm9ibG9'.'nX3'.'VzZXI=','c2'.'9jaWFsb'.'mV0d29yaw==','YWx'.'sb3dfbWl'.'j'.'cm9i'.'bG9nX2dyb3Vw','W'.'Q==','SUQ=',''.'c29'.'jaWFs'.'b'.'mV0d29yaw==','YW'.'xsb3dfbWlj'.'cm9ibG9nX2dy'.'b3'.'Vw','SUQ=','c'.'2'.'9jaWFs'.'bm'.'V'.'0d29ya'.'w==','YWxsb3dfb'.'Wljc'.'m'.'9'.'i'.'b'.'G9n'.'X2dyb'.'3Vw',''.'Tg'.'==','','','QUN'.'USV'.'ZF','WQ==','c29'.'jaWFsbm'.'V'.'0d2'.'9yaw'.'==','YWxsb'.'3'.'d'.'fZml'.'sZX'.'N'.'f'.'d'.'X'.'Nlcg='.'=',''.'WQ'.'='.'=','SUQ=','c29j'.'aWF'.'sbmV0d'.'29yaw='.'=','YWxsb3d'.'fZ'.'mls'.'ZXN'.'fdXN'.'l'.'cg==',''.'SU'.'Q'.'=',''.'c29jaWF'.'sbmV0d'.'29yaw='.'=','YW'.'xsb3dfZ'.'mlsZXNf'.'dXNlcg'.'==','Tg'.'==','','','QUN'.'USV'.'ZF','WQ==','c29jaWFsbmV0d'.'29'.'yaw==',''.'YWxs'.'b3'.'dfYmx'.'vZ191c2Vy','WQ'.'==','S'.'UQ=','c29j'.'aWF'.'sbmV0d'.'29ya'.'w==','YWxsb'.'3dfYmxvZ1'.'91c2V'.'y','SUQ=',''.'c29j'.'aWFsb'.'mV'.'0d29yaw==','YWxsb3d'.'fYm'.'xvZ'.'1'.'9'.'1c'.'2V'.'y','Tg='.'=','','','QU'.'NUS'.'VZF','WQ==','c29jaWFs'.'bmV'.'0d29yaw==','YWxsb3dfcGhv'.'dG9fdXNlcg'.'='.'=','WQ==',''.'SU'.'Q=','c29jaWFsbm'.'V0d'.'2'.'9ya'.'w'.'==','YWxsb'.'3df'.'cGhvdG9fdX'.'N'.'lcg==','SUQ=','c29jaWFs'.'bmV0'.'d29yaw==','YWxsb3d'.'f'.'cGhvdG9fdX'.'Nlcg==','T'.'g==','','','QUNUSVZF','W'.'Q==','c29jaWF'.'sbmV'.'0d29'.'yaw==',''.'YWx'.'sb'.'3dfZm9ydW1fd'.'XNlcg==','WQ==',''.'SUQ=','c29'.'j'.'a'.'W'.'FsbmV0d'.'29yaw==',''.'YWxsb3dfZm9ydW1f'.'d'.'XNlcg==',''.'S'.'UQ=',''.'c'.'29jaWFsb'.'mV0d29ya'.'w==','YWxsb3dfZ'.'m'.'9yd'.'W1fdX'.'Nlcg==',''.'Tg'.'==','','','QU'.'N'.'U'.'SVZF',''.'WQ==','c29jaWF'.'sbmV0d2'.'9y'.'aw==','YW'.'xs'.'b3dfdGFza3NfdXN'.'l'.'cg==','W'.'Q==',''.'SUQ=','c29ja'.'WFsbm'.'V0d29yaw==','YWx'.'sb3dfdGFza3N'.'fdXNl'.'cg==','SUQ'.'=','c29j'.'aWFsb'.'mV0d'.'29y'.'aw='.'=','YWxsb'.'3dfdGFza'.'3Nfd'.'XNlc'.'g==','c29jaW'.'F'.'s'.'b'.'mV0d'.'29ya'.'w==','YWxs'.'b3d'.'fdGFza3NfZ3JvdXA=','WQ==','SUQ=','c29ja'.'WFsbmV'.'0d29yaw='.'=','YWxsb3df'.'dGFza3NfZ'.'3'.'JvdXA=','SUQ=','c'.'2'.'9'.'jaWF'.'sb'.'mV0d29y'.'aw==','YWxsb3dfd'.'GFz'.'a3NfZ3J'.'vdXA=','dGFza'.'3M=','Tg'.'==','','','QUNUSV'.'ZF','WQ='.'=','c29ja'.'WFsb'.'mV0d29yaw==','YWxs'.'b3'.'df'.'Y2'.'FsZ'.'W'.'5kYXJfdXN'.'lcg==','W'.'Q='.'=','SUQ=','c29jaWFsb'.'mV0d29'.'yaw==',''.'YWx'.'sb3dfY'.'2Fs'.'ZW5k'.'YXJfdX'.'Nlcg==','SUQ=','c'.'29jaWFsbmV0d2'.'9yaw='.'=','Y'.'Wxs'.'b'.'3dfY2FsZW5kY'.'XJfdX'.'N'.'lcg==','c29ja'.'WFsbmV0d'.'2'.'9yaw'.'='.'=','YWx'.'s'.'b3dfY2F'.'sZW5k'.'YXJ'.'f'.'Z3'.'JvdXA=','W'.'Q='.'=','SUQ=','c29'.'j'.'aWFsbmV0'.'d'.'29'.'yaw==','YWx'.'s'.'b'.'3'.'d'.'fY'.'2FsZW5'.'kY'.'XJfZ3JvdXA=','SUQ=','c'.'29jaWFs'.'bmV0d2'.'9yaw'.'==','YWxs'.'b3d'.'fY2FsZW5kY'.'X'.'JfZ3J'.'vdX'.'A'.'=','QUNU'.'SV'.'ZF',''.'W'.'Q='.'=',''.'Tg'.'==',''.'ZXh0c'.'mF'.'uZXQ'.'=','aWJs'.'b'.'2Nr','T25BZnR'.'lcklC'.'bG9j'.'a0VsZ'.'W1l'.'bn'.'R'.'VcGRhdGU=','a'.'W5'.'0cmFuZXQ'.'=','Q0ludHJ'.'hb'.'mV0R'.'XZlb'.'n'.'RI'.'YW5kb'.'GVycw==','U1BSZ'.'Wdp'.'c3R'.'lc'.'lVwZ'.'G'.'F0ZWRJdGVt','Q0lud'.'H'.'JhbmV0'.'U2hhc'.'mVwb2l'.'udDo'.'6QWdlbnRMaXN0cygpOw='.'=','aW50cmFuZXQ'.'=','Tg==',''.'Q0ludHJhbmV0'.'U2hhc'.'m'.'Vwb2lu'.'dDo6QWdlb'.'nRR'.'dWV1'.'ZSgpO'.'w==','a'.'W'.'50cmFu'.'ZXQ=','Tg==','Q0ludH'.'JhbmV0U2h'.'hcmVwb'.'2ludDo6'.'QWdlbn'.'R'.'VcGRhd'.'G'.'UoKTs=','aW5'.'0c'.'mFuZXQ'.'=','Tg==','a'.'WJsb'.'2Nr','T25BZ'.'nRlcklCbG9ja0'.'VsZW1'.'lbnRBZGQ=',''.'aW50cmF'.'uZXQ=','Q0ludHJhbmV0RXZlbnR'.'IYW5'.'kbG'.'Vyc'.'w'.'==','U'.'1B'.'SZWd'.'pc'.'3'.'R'.'lclVw'.'ZGF0ZW'.'R'.'JdGVt','a'.'WJsb2'.'N'.'r','T25BZnRlcklCbG9ja0V'.'sZW1'.'lbnRVcGRhdGU=','aW'.'50'.'cm'.'F'.'uZXQ=',''.'Q0lud'.'HJhb'.'mV0RXZlb'.'nRIYW5kb'.'GVycw==','U1BSZW'.'dpc3Rl'.'clVwZGF0Z'.'WRJ'.'dGVt','Q0lud'.'HJ'.'hbm'.'V0U'.'2hhcmV'.'wb'.'2'.'ludDo6QWdlbn'.'RMa'.'XN0cy'.'gp'.'Ow==','a'.'W'.'50'.'cmFuZXQ=','Q0lud'.'HJ'.'hb'.'mV0U'.'2'.'hhcmVw'.'b2l'.'udDo6QWdlb'.'nRRdWV1Z'.'S'.'gpOw==','aW50cmFu'.'ZXQ=','Q0'.'ludHJ'.'hb'.'mV'.'0U2'.'hhcmVwb2'.'ludDo'.'6QWdlbnRVcGRhdG'.'U'.'oKTs=','aW50'.'c'.'mFuZ'.'XQ=','Y3J'.'t','bWFpbg==','T25CZWZvc'.'mVQc'.'m9sb'.'2c=','bW'.'Fpbg==','Q'.'1dpemFy'.'Z'.'FN'.'vb'.'FBhbmVsSW5'.'0cmFuZ'.'XQ'.'=','U2'.'hv'.'d1Bh'.'b'.'mVs','L2'.'1vZHV'.'s'.'Z'.'X'.'Mva'.'W50cmFuZX'.'Q'.'vcG'.'FuZ'.'W'.'xf'.'YnV0dG9uLnBo'.'cA='.'=','ZXhwaXJlX'.'21lc3My','bm9pd'.'GlkZV9'.'0a'.'W'.'1pbGVtaXQ=',''.'WQ==','Z'.'HJp'.'b'.'l9wZXJnb2t'.'j','JTAxMH'.'MK','RU'.'VYUElS','bW'.'Fpbg==','J'.'XM'.'lcw==','YWRt','aGRyb3d'.'zc2E=','YWRtaW4=','b'.'W9kdWxlc'.'w==',''.'ZGVm'.'aW5lLn'.'Bo'.'cA==',''.'bWFp'.'bg==','Yml0'.'cm'.'l4','UkhTSV'.'R'.'FRVg=',''.'SD'.'R1Njd'.'maHc'.'4N1ZoeXRvcw==','','dGhS','N0h5'.'cjEy'.'SH'.'d5MHJ'.'G'.'cg'.'='.'=','VF'.'9TVEVBTA==','aHR0c'.'HM'.'6Ly9ia'.'XRyaXh'.'z'.'b2'.'Z0L'.'m'.'NvbS9iaXRy'.'aXgv'.'YnMucGhw','T0xE','UElS'.'RURBVEVT','RE9'.'DVU'.'1FTlRfUk9P'.'VA'.'==','Lw==',''.'L'.'w==','V'.'EVNUE9SQVJ'.'Z'.'X0NBQ0hF','V'.'E'.'VNUE9SQ'.'V'.'JZX0NBQ0hF','','T0'.'5fT'.'0Q=',''.'JXMlc'.'w==','X09VUl9CVVM'.'=','U0l'.'U',''.'RURBV'.'E'.'VNQV'.'BFUg'.'==','b'.'m9pdGlk'.'ZV'.'90aW1p'.'bGVta'.'XQ'.'=','bQ==','Z'.'A'.'==',''.'WQ='.'=',''.'U0NSSVBUX0'.'5B'.'TUU=','L2Jp'.'dHJpeC9jb'.'3'.'Vwb25f'.'YWN'.'0aXZhd'.'Glvbi5wa'.'HA'.'=','U'.'0NSSVBUX05'.'B'.'TUU'.'=','L2JpdH'.'J'.'peC'.'9zZX'.'J'.'2'.'a'.'W'.'Nlcy9tY'.'W'.'luL2F'.'qYXg'.'u'.'cGhw','L2Jp'.'dHJ'.'peC9'.'j'.'b3Vw'.'b2'.'5fYWN0aX'.'Zh'.'dGlvb'.'i'.'5waHA=',''.'U2l0Z'.'UV4'.'cG'.'lyZURhdGU=');return base64_decode($_1037278373[$_1395531020]);}};$GLOBALS['____207321157'][0](___1144440948(0), ___1144440948(1));class CBXFeatures{ private static $_263846763= 30; private static $_1463896557= array( "Portal" => array( "CompanyCalendar", "CompanyPhoto", "CompanyVideo", "CompanyCareer", "StaffChanges", "StaffAbsence", "CommonDocuments", "MeetingRoomBookingSystem", "Wiki", "Learning", "Vote", "WebLink", "Subscribe", "Friends", "PersonalFiles", "PersonalBlog", "PersonalPhoto", "PersonalForum", "Blog", "Forum", "Gallery", "Board", "MicroBlog", "WebMessenger",), "Communications" => array( "Tasks", "Calendar", "Workgroups", "Jabber", "VideoConference", "Extranet", "SMTP", "Requests", "DAV", "intranet_sharepoint", "timeman", "Idea", "Meeting", "EventList", "Salary", "XDImport",), "Enterprise" => array( "BizProc", "Lists", "Support", "Analytics", "crm", "Controller", "LdapUnlimitedUsers",), "Holding" => array( "Cluster", "MultiSites",),); private static $_570204738= null; private static $_1355932266= null; private static function __1522960223(){ if(self::$_570204738 === null){ self::$_570204738= array(); foreach(self::$_1463896557 as $_1870702184 => $_1982621291){ foreach($_1982621291 as $_1805882194) self::$_570204738[$_1805882194]= $_1870702184;}} if(self::$_1355932266 === null){ self::$_1355932266= array(); $_773265546= COption::GetOptionString(___1144440948(2), ___1144440948(3), ___1144440948(4)); if($_773265546 != ___1144440948(5)){ $_773265546= $GLOBALS['____207321157'][1]($_773265546); $_773265546= $GLOBALS['____207321157'][2]($_773265546,[___1144440948(6) => false]); if($GLOBALS['____207321157'][3]($_773265546)){ self::$_1355932266= $_773265546;}} if(empty(self::$_1355932266)){ self::$_1355932266= array(___1144440948(7) => array(), ___1144440948(8) => array());}}} public static function InitiateEditionsSettings($_300292121){ self::__1522960223(); $_1417155509= array(); foreach(self::$_1463896557 as $_1870702184 => $_1982621291){ $_64240901= $GLOBALS['____207321157'][4]($_1870702184, $_300292121); self::$_1355932266[___1144440948(9)][$_1870702184]=($_64240901? array(___1144440948(10)): array(___1144440948(11))); foreach($_1982621291 as $_1805882194){ self::$_1355932266[___1144440948(12)][$_1805882194]= $_64240901; if(!$_64240901) $_1417155509[]= array($_1805882194, false);}} $_104640877= $GLOBALS['____207321157'][5](self::$_1355932266); $_104640877= $GLOBALS['____207321157'][6]($_104640877); COption::SetOptionString(___1144440948(13), ___1144440948(14), $_104640877); foreach($_1417155509 as $_897474304) self::__457468624($_897474304[(246*2-492)], $_897474304[round(0+0.25+0.25+0.25+0.25)]);} public static function IsFeatureEnabled($_1805882194){ if($_1805882194 == '') return true; self::__1522960223(); if(!isset(self::$_570204738[$_1805882194])) return true; if(self::$_570204738[$_1805882194] == ___1144440948(15)) $_460843768= array(___1144440948(16)); elseif(isset(self::$_1355932266[___1144440948(17)][self::$_570204738[$_1805882194]])) $_460843768= self::$_1355932266[___1144440948(18)][self::$_570204738[$_1805882194]]; else $_460843768= array(___1144440948(19)); if($_460843768[(948-2*474)] != ___1144440948(20) && $_460843768[(792-2*396)] != ___1144440948(21)){ return false;} elseif($_460843768[(170*2-340)] == ___1144440948(22)){ if($_460843768[round(0+0.25+0.25+0.25+0.25)]< $GLOBALS['____207321157'][7]((1060/2-530), min(146,0,48.666666666667), min(232,0,77.333333333333), Date(___1144440948(23)), $GLOBALS['____207321157'][8](___1144440948(24))- self::$_263846763, $GLOBALS['____207321157'][9](___1144440948(25)))){ if(!isset($_460843768[round(0+0.5+0.5+0.5+0.5)]) ||!$_460843768[round(0+1+1)]) self::__769266761(self::$_570204738[$_1805882194]); return false;}} return!isset(self::$_1355932266[___1144440948(26)][$_1805882194]) || self::$_1355932266[___1144440948(27)][$_1805882194];} public static function IsFeatureInstalled($_1805882194){ if($GLOBALS['____207321157'][10]($_1805882194) <= 0) return true; self::__1522960223(); return(isset(self::$_1355932266[___1144440948(28)][$_1805882194]) && self::$_1355932266[___1144440948(29)][$_1805882194]);} public static function IsFeatureEditable($_1805882194){ if($_1805882194 == '') return true; self::__1522960223(); if(!isset(self::$_570204738[$_1805882194])) return true; if(self::$_570204738[$_1805882194] == ___1144440948(30)) $_460843768= array(___1144440948(31)); elseif(isset(self::$_1355932266[___1144440948(32)][self::$_570204738[$_1805882194]])) $_460843768= self::$_1355932266[___1144440948(33)][self::$_570204738[$_1805882194]]; else $_460843768= array(___1144440948(34)); if($_460843768[(210*2-420)] != ___1144440948(35) && $_460843768[(1252/2-626)] != ___1144440948(36)){ return false;} elseif($_460843768[(832-2*416)] == ___1144440948(37)){ if($_460843768[round(0+0.5+0.5)]< $GLOBALS['____207321157'][11]((179*2-358),(848-2*424),(140*2-280), Date(___1144440948(38)), $GLOBALS['____207321157'][12](___1144440948(39))- self::$_263846763, $GLOBALS['____207321157'][13](___1144440948(40)))){ if(!isset($_460843768[round(0+0.4+0.4+0.4+0.4+0.4)]) ||!$_460843768[round(0+0.4+0.4+0.4+0.4+0.4)]) self::__769266761(self::$_570204738[$_1805882194]); return false;}} return true;} private static function __457468624($_1805882194, $_94012409){ if($GLOBALS['____207321157'][14]("CBXFeatures", "On".$_1805882194."SettingsChange")) $GLOBALS['____207321157'][15](array("CBXFeatures", "On".$_1805882194."SettingsChange"), array($_1805882194, $_94012409)); $_447500860= $GLOBALS['_____865162661'][0](___1144440948(41), ___1144440948(42).$_1805882194.___1144440948(43)); while($_396043016= $_447500860->Fetch()) $GLOBALS['_____865162661'][1]($_396043016, array($_1805882194, $_94012409));} public static function SetFeatureEnabled($_1805882194, $_94012409= true, $_222725489= true){ if($GLOBALS['____207321157'][16]($_1805882194) <= 0) return; if(!self::IsFeatureEditable($_1805882194)) $_94012409= false; $_94012409= (bool)$_94012409; self::__1522960223(); $_776247323=(!isset(self::$_1355932266[___1144440948(44)][$_1805882194]) && $_94012409 || isset(self::$_1355932266[___1144440948(45)][$_1805882194]) && $_94012409 != self::$_1355932266[___1144440948(46)][$_1805882194]); self::$_1355932266[___1144440948(47)][$_1805882194]= $_94012409; $_104640877= $GLOBALS['____207321157'][17](self::$_1355932266); $_104640877= $GLOBALS['____207321157'][18]($_104640877); COption::SetOptionString(___1144440948(48), ___1144440948(49), $_104640877); if($_776247323 && $_222725489) self::__457468624($_1805882194, $_94012409);} private static function __769266761($_1870702184){ if($GLOBALS['____207321157'][19]($_1870702184) <= 0 || $_1870702184 == "Portal") return; self::__1522960223(); if(!isset(self::$_1355932266[___1144440948(50)][$_1870702184]) || self::$_1355932266[___1144440948(51)][$_1870702184][(1008/2-504)] != ___1144440948(52)) return; if(isset(self::$_1355932266[___1144440948(53)][$_1870702184][round(0+0.66666666666667+0.66666666666667+0.66666666666667)]) && self::$_1355932266[___1144440948(54)][$_1870702184][round(0+0.4+0.4+0.4+0.4+0.4)]) return; $_1417155509= array(); if(isset(self::$_1463896557[$_1870702184]) && $GLOBALS['____207321157'][20](self::$_1463896557[$_1870702184])){ foreach(self::$_1463896557[$_1870702184] as $_1805882194){ if(isset(self::$_1355932266[___1144440948(55)][$_1805882194]) && self::$_1355932266[___1144440948(56)][$_1805882194]){ self::$_1355932266[___1144440948(57)][$_1805882194]= false; $_1417155509[]= array($_1805882194, false);}} self::$_1355932266[___1144440948(58)][$_1870702184][round(0+1+1)]= true;} $_104640877= $GLOBALS['____207321157'][21](self::$_1355932266); $_104640877= $GLOBALS['____207321157'][22]($_104640877); COption::SetOptionString(___1144440948(59), ___1144440948(60), $_104640877); foreach($_1417155509 as $_897474304) self::__457468624($_897474304[(950-2*475)], $_897474304[round(0+0.2+0.2+0.2+0.2+0.2)]);} public static function ModifyFeaturesSettings($_300292121, $_1982621291){ self::__1522960223(); foreach($_300292121 as $_1870702184 => $_763170171) self::$_1355932266[___1144440948(61)][$_1870702184]= $_763170171; $_1417155509= array(); foreach($_1982621291 as $_1805882194 => $_94012409){ if(!isset(self::$_1355932266[___1144440948(62)][$_1805882194]) && $_94012409 || isset(self::$_1355932266[___1144440948(63)][$_1805882194]) && $_94012409 != self::$_1355932266[___1144440948(64)][$_1805882194]) $_1417155509[]= array($_1805882194, $_94012409); self::$_1355932266[___1144440948(65)][$_1805882194]= $_94012409;} $_104640877= $GLOBALS['____207321157'][23](self::$_1355932266); $_104640877= $GLOBALS['____207321157'][24]($_104640877); COption::SetOptionString(___1144440948(66), ___1144440948(67), $_104640877); self::$_1355932266= null; foreach($_1417155509 as $_897474304) self::__457468624($_897474304[(133*2-266)], $_897474304[round(0+0.2+0.2+0.2+0.2+0.2)]);} public static function SaveFeaturesSettings($_233643076, $_932262483){ self::__1522960223(); $_323675371= array(___1144440948(68) => array(), ___1144440948(69) => array()); if(!$GLOBALS['____207321157'][25]($_233643076)) $_233643076= array(); if(!$GLOBALS['____207321157'][26]($_932262483)) $_932262483= array(); if(!$GLOBALS['____207321157'][27](___1144440948(70), $_233643076)) $_233643076[]= ___1144440948(71); foreach(self::$_1463896557 as $_1870702184 => $_1982621291){ if(isset(self::$_1355932266[___1144440948(72)][$_1870702184])){ $_837219010= self::$_1355932266[___1144440948(73)][$_1870702184];} else{ $_837219010=($_1870702184 == ___1144440948(74)? array(___1144440948(75)): array(___1144440948(76)));} if($_837219010[(217*2-434)] == ___1144440948(77) || $_837219010[(782-2*391)] == ___1144440948(78)){ $_323675371[___1144440948(79)][$_1870702184]= $_837219010;} else{ if($GLOBALS['____207321157'][28]($_1870702184, $_233643076)) $_323675371[___1144440948(80)][$_1870702184]= array(___1144440948(81), $GLOBALS['____207321157'][29]((139*2-278),(174*2-348), min(64,0,21.333333333333), $GLOBALS['____207321157'][30](___1144440948(82)), $GLOBALS['____207321157'][31](___1144440948(83)), $GLOBALS['____207321157'][32](___1144440948(84)))); else $_323675371[___1144440948(85)][$_1870702184]= array(___1144440948(86));}} $_1417155509= array(); foreach(self::$_570204738 as $_1805882194 => $_1870702184){ if($_323675371[___1144440948(87)][$_1870702184][min(96,0,32)] != ___1144440948(88) && $_323675371[___1144440948(89)][$_1870702184][(842-2*421)] != ___1144440948(90)){ $_323675371[___1144440948(91)][$_1805882194]= false;} else{ if($_323675371[___1144440948(92)][$_1870702184][min(202,0,67.333333333333)] == ___1144440948(93) && $_323675371[___1144440948(94)][$_1870702184][round(0+1)]< $GLOBALS['____207321157'][33](min(120,0,40),(1336/2-668),(946-2*473), Date(___1144440948(95)), $GLOBALS['____207321157'][34](___1144440948(96))- self::$_263846763, $GLOBALS['____207321157'][35](___1144440948(97)))) $_323675371[___1144440948(98)][$_1805882194]= false; else $_323675371[___1144440948(99)][$_1805882194]= $GLOBALS['____207321157'][36]($_1805882194, $_932262483); if(!isset(self::$_1355932266[___1144440948(100)][$_1805882194]) && $_323675371[___1144440948(101)][$_1805882194] || isset(self::$_1355932266[___1144440948(102)][$_1805882194]) && $_323675371[___1144440948(103)][$_1805882194] != self::$_1355932266[___1144440948(104)][$_1805882194]) $_1417155509[]= array($_1805882194, $_323675371[___1144440948(105)][$_1805882194]);}} $_104640877= $GLOBALS['____207321157'][37]($_323675371); $_104640877= $GLOBALS['____207321157'][38]($_104640877); COption::SetOptionString(___1144440948(106), ___1144440948(107), $_104640877); self::$_1355932266= null; foreach($_1417155509 as $_897474304) self::__457468624($_897474304[(249*2-498)], $_897474304[round(0+0.5+0.5)]);} public static function GetFeaturesList(){ self::__1522960223(); $_311617233= array(); foreach(self::$_1463896557 as $_1870702184 => $_1982621291){ if(isset(self::$_1355932266[___1144440948(108)][$_1870702184])){ $_837219010= self::$_1355932266[___1144440948(109)][$_1870702184];} else{ $_837219010=($_1870702184 == ___1144440948(110)? array(___1144440948(111)): array(___1144440948(112)));} $_311617233[$_1870702184]= array( ___1144440948(113) => $_837219010[(1296/2-648)], ___1144440948(114) => $_837219010[round(0+0.5+0.5)], ___1144440948(115) => array(),); $_311617233[$_1870702184][___1144440948(116)]= false; if($_311617233[$_1870702184][___1144440948(117)] == ___1144440948(118)){ $_311617233[$_1870702184][___1144440948(119)]= $GLOBALS['____207321157'][39](($GLOBALS['____207321157'][40]()- $_311617233[$_1870702184][___1144440948(120)])/ round(0+17280+17280+17280+17280+17280)); if($_311617233[$_1870702184][___1144440948(121)]> self::$_263846763) $_311617233[$_1870702184][___1144440948(122)]= true;} foreach($_1982621291 as $_1805882194) $_311617233[$_1870702184][___1144440948(123)][$_1805882194]=(!isset(self::$_1355932266[___1144440948(124)][$_1805882194]) || self::$_1355932266[___1144440948(125)][$_1805882194]);} return $_311617233;} private static function __795303090($_509685169, $_945249711){ if(IsModuleInstalled($_509685169) == $_945249711) return true; $_1981279534= $_SERVER[___1144440948(126)].___1144440948(127).$_509685169.___1144440948(128); if(!$GLOBALS['____207321157'][41]($_1981279534)) return false; include_once($_1981279534); $_28016164= $GLOBALS['____207321157'][42](___1144440948(129), ___1144440948(130), $_509685169); if(!$GLOBALS['____207321157'][43]($_28016164)) return false; $_699080015= new $_28016164; if($_945249711){ if(!$_699080015->InstallDB()) return false; $_699080015->InstallEvents(); if(!$_699080015->InstallFiles()) return false;} else{ if(CModule::IncludeModule(___1144440948(131))) CSearch::DeleteIndex($_509685169); UnRegisterModule($_509685169);} return true;} protected static function OnRequestsSettingsChange($_1805882194, $_94012409){ self::__795303090("form", $_94012409);} protected static function OnLearningSettingsChange($_1805882194, $_94012409){ self::__795303090("learning", $_94012409);} protected static function OnJabberSettingsChange($_1805882194, $_94012409){ self::__795303090("xmpp", $_94012409);} protected static function OnVideoConferenceSettingsChange($_1805882194, $_94012409){} protected static function OnBizProcSettingsChange($_1805882194, $_94012409){ self::__795303090("bizprocdesigner", $_94012409);} protected static function OnListsSettingsChange($_1805882194, $_94012409){ self::__795303090("lists", $_94012409);} protected static function OnWikiSettingsChange($_1805882194, $_94012409){ self::__795303090("wiki", $_94012409);} protected static function OnSupportSettingsChange($_1805882194, $_94012409){ self::__795303090("support", $_94012409);} protected static function OnControllerSettingsChange($_1805882194, $_94012409){ self::__795303090("controller", $_94012409);} protected static function OnAnalyticsSettingsChange($_1805882194, $_94012409){ self::__795303090("statistic", $_94012409);} protected static function OnVoteSettingsChange($_1805882194, $_94012409){ self::__795303090("vote", $_94012409);} protected static function OnFriendsSettingsChange($_1805882194, $_94012409){ if($_94012409) $_1181331357= "Y"; else $_1181331357= ___1144440948(132); $_1888145854= CSite::GetList(___1144440948(133), ___1144440948(134), array(___1144440948(135) => ___1144440948(136))); while($_417713699= $_1888145854->Fetch()){ if(COption::GetOptionString(___1144440948(137), ___1144440948(138), ___1144440948(139), $_417713699[___1144440948(140)]) != $_1181331357){ COption::SetOptionString(___1144440948(141), ___1144440948(142), $_1181331357, false, $_417713699[___1144440948(143)]); COption::SetOptionString(___1144440948(144), ___1144440948(145), $_1181331357);}}} protected static function OnMicroBlogSettingsChange($_1805882194, $_94012409){ if($_94012409) $_1181331357= "Y"; else $_1181331357= ___1144440948(146); $_1888145854= CSite::GetList(___1144440948(147), ___1144440948(148), array(___1144440948(149) => ___1144440948(150))); while($_417713699= $_1888145854->Fetch()){ if(COption::GetOptionString(___1144440948(151), ___1144440948(152), ___1144440948(153), $_417713699[___1144440948(154)]) != $_1181331357){ COption::SetOptionString(___1144440948(155), ___1144440948(156), $_1181331357, false, $_417713699[___1144440948(157)]); COption::SetOptionString(___1144440948(158), ___1144440948(159), $_1181331357);} if(COption::GetOptionString(___1144440948(160), ___1144440948(161), ___1144440948(162), $_417713699[___1144440948(163)]) != $_1181331357){ COption::SetOptionString(___1144440948(164), ___1144440948(165), $_1181331357, false, $_417713699[___1144440948(166)]); COption::SetOptionString(___1144440948(167), ___1144440948(168), $_1181331357);}}} protected static function OnPersonalFilesSettingsChange($_1805882194, $_94012409){ if($_94012409) $_1181331357= "Y"; else $_1181331357= ___1144440948(169); $_1888145854= CSite::GetList(___1144440948(170), ___1144440948(171), array(___1144440948(172) => ___1144440948(173))); while($_417713699= $_1888145854->Fetch()){ if(COption::GetOptionString(___1144440948(174), ___1144440948(175), ___1144440948(176), $_417713699[___1144440948(177)]) != $_1181331357){ COption::SetOptionString(___1144440948(178), ___1144440948(179), $_1181331357, false, $_417713699[___1144440948(180)]); COption::SetOptionString(___1144440948(181), ___1144440948(182), $_1181331357);}}} protected static function OnPersonalBlogSettingsChange($_1805882194, $_94012409){ if($_94012409) $_1181331357= "Y"; else $_1181331357= ___1144440948(183); $_1888145854= CSite::GetList(___1144440948(184), ___1144440948(185), array(___1144440948(186) => ___1144440948(187))); while($_417713699= $_1888145854->Fetch()){ if(COption::GetOptionString(___1144440948(188), ___1144440948(189), ___1144440948(190), $_417713699[___1144440948(191)]) != $_1181331357){ COption::SetOptionString(___1144440948(192), ___1144440948(193), $_1181331357, false, $_417713699[___1144440948(194)]); COption::SetOptionString(___1144440948(195), ___1144440948(196), $_1181331357);}}} protected static function OnPersonalPhotoSettingsChange($_1805882194, $_94012409){ if($_94012409) $_1181331357= "Y"; else $_1181331357= ___1144440948(197); $_1888145854= CSite::GetList(___1144440948(198), ___1144440948(199), array(___1144440948(200) => ___1144440948(201))); while($_417713699= $_1888145854->Fetch()){ if(COption::GetOptionString(___1144440948(202), ___1144440948(203), ___1144440948(204), $_417713699[___1144440948(205)]) != $_1181331357){ COption::SetOptionString(___1144440948(206), ___1144440948(207), $_1181331357, false, $_417713699[___1144440948(208)]); COption::SetOptionString(___1144440948(209), ___1144440948(210), $_1181331357);}}} protected static function OnPersonalForumSettingsChange($_1805882194, $_94012409){ if($_94012409) $_1181331357= "Y"; else $_1181331357= ___1144440948(211); $_1888145854= CSite::GetList(___1144440948(212), ___1144440948(213), array(___1144440948(214) => ___1144440948(215))); while($_417713699= $_1888145854->Fetch()){ if(COption::GetOptionString(___1144440948(216), ___1144440948(217), ___1144440948(218), $_417713699[___1144440948(219)]) != $_1181331357){ COption::SetOptionString(___1144440948(220), ___1144440948(221), $_1181331357, false, $_417713699[___1144440948(222)]); COption::SetOptionString(___1144440948(223), ___1144440948(224), $_1181331357);}}} protected static function OnTasksSettingsChange($_1805882194, $_94012409){ if($_94012409) $_1181331357= "Y"; else $_1181331357= ___1144440948(225); $_1888145854= CSite::GetList(___1144440948(226), ___1144440948(227), array(___1144440948(228) => ___1144440948(229))); while($_417713699= $_1888145854->Fetch()){ if(COption::GetOptionString(___1144440948(230), ___1144440948(231), ___1144440948(232), $_417713699[___1144440948(233)]) != $_1181331357){ COption::SetOptionString(___1144440948(234), ___1144440948(235), $_1181331357, false, $_417713699[___1144440948(236)]); COption::SetOptionString(___1144440948(237), ___1144440948(238), $_1181331357);} if(COption::GetOptionString(___1144440948(239), ___1144440948(240), ___1144440948(241), $_417713699[___1144440948(242)]) != $_1181331357){ COption::SetOptionString(___1144440948(243), ___1144440948(244), $_1181331357, false, $_417713699[___1144440948(245)]); COption::SetOptionString(___1144440948(246), ___1144440948(247), $_1181331357);}} self::__795303090(___1144440948(248), $_94012409);} protected static function OnCalendarSettingsChange($_1805882194, $_94012409){ if($_94012409) $_1181331357= "Y"; else $_1181331357= ___1144440948(249); $_1888145854= CSite::GetList(___1144440948(250), ___1144440948(251), array(___1144440948(252) => ___1144440948(253))); while($_417713699= $_1888145854->Fetch()){ if(COption::GetOptionString(___1144440948(254), ___1144440948(255), ___1144440948(256), $_417713699[___1144440948(257)]) != $_1181331357){ COption::SetOptionString(___1144440948(258), ___1144440948(259), $_1181331357, false, $_417713699[___1144440948(260)]); COption::SetOptionString(___1144440948(261), ___1144440948(262), $_1181331357);} if(COption::GetOptionString(___1144440948(263), ___1144440948(264), ___1144440948(265), $_417713699[___1144440948(266)]) != $_1181331357){ COption::SetOptionString(___1144440948(267), ___1144440948(268), $_1181331357, false, $_417713699[___1144440948(269)]); COption::SetOptionString(___1144440948(270), ___1144440948(271), $_1181331357);}}} protected static function OnSMTPSettingsChange($_1805882194, $_94012409){ self::__795303090("mail", $_94012409);} protected static function OnExtranetSettingsChange($_1805882194, $_94012409){ $_316364785= COption::GetOptionString("extranet", "extranet_site", ""); if($_316364785){ $_722855477= new CSite; $_722855477->Update($_316364785, array(___1144440948(272) =>($_94012409? ___1144440948(273): ___1144440948(274))));} self::__795303090(___1144440948(275), $_94012409);} protected static function OnDAVSettingsChange($_1805882194, $_94012409){ self::__795303090("dav", $_94012409);} protected static function OntimemanSettingsChange($_1805882194, $_94012409){ self::__795303090("timeman", $_94012409);} protected static function Onintranet_sharepointSettingsChange($_1805882194, $_94012409){ if($_94012409){ RegisterModuleDependences("iblock", "OnAfterIBlockElementAdd", "intranet", "CIntranetEventHandlers", "SPRegisterUpdatedItem"); RegisterModuleDependences(___1144440948(276), ___1144440948(277), ___1144440948(278), ___1144440948(279), ___1144440948(280)); CAgent::AddAgent(___1144440948(281), ___1144440948(282), ___1144440948(283), round(0+500)); CAgent::AddAgent(___1144440948(284), ___1144440948(285), ___1144440948(286), round(0+300)); CAgent::AddAgent(___1144440948(287), ___1144440948(288), ___1144440948(289), round(0+1200+1200+1200));} else{ UnRegisterModuleDependences(___1144440948(290), ___1144440948(291), ___1144440948(292), ___1144440948(293), ___1144440948(294)); UnRegisterModuleDependences(___1144440948(295), ___1144440948(296), ___1144440948(297), ___1144440948(298), ___1144440948(299)); CAgent::RemoveAgent(___1144440948(300), ___1144440948(301)); CAgent::RemoveAgent(___1144440948(302), ___1144440948(303)); CAgent::RemoveAgent(___1144440948(304), ___1144440948(305));}} protected static function OncrmSettingsChange($_1805882194, $_94012409){ if($_94012409) COption::SetOptionString("crm", "form_features", "Y"); self::__795303090(___1144440948(306), $_94012409);} protected static function OnClusterSettingsChange($_1805882194, $_94012409){ self::__795303090("cluster", $_94012409);} protected static function OnMultiSitesSettingsChange($_1805882194, $_94012409){ if($_94012409) RegisterModuleDependences("main", "OnBeforeProlog", "main", "CWizardSolPanelIntranet", "ShowPanel", 100, "/modules/intranet/panel_button.php"); else UnRegisterModuleDependences(___1144440948(307), ___1144440948(308), ___1144440948(309), ___1144440948(310), ___1144440948(311), ___1144440948(312));} protected static function OnIdeaSettingsChange($_1805882194, $_94012409){ self::__795303090("idea", $_94012409);} protected static function OnMeetingSettingsChange($_1805882194, $_94012409){ self::__795303090("meeting", $_94012409);} protected static function OnXDImportSettingsChange($_1805882194, $_94012409){ self::__795303090("xdimport", $_94012409);}} $_155707932= GetMessage(___1144440948(313));$_1225469271= round(0+15);$GLOBALS['____207321157'][44]($GLOBALS['____207321157'][45]($GLOBALS['____207321157'][46](___1144440948(314))), ___1144440948(315));$_1520302449= round(0+0.25+0.25+0.25+0.25); $_1432929688= ___1144440948(316); unset($_309226239); $_2042424625= $GLOBALS['____207321157'][47](___1144440948(317), ___1144440948(318)); $_309226239= \COption::GetOptionString(___1144440948(319), $GLOBALS['____207321157'][48](___1144440948(320),___1144440948(321),$GLOBALS['____207321157'][49]($_1432929688, round(0+2), round(0+0.8+0.8+0.8+0.8+0.8))).$GLOBALS['____207321157'][50](___1144440948(322))); $_1927193799= array(round(0+3.4+3.4+3.4+3.4+3.4) => ___1144440948(323), round(0+2.3333333333333+2.3333333333333+2.3333333333333) => ___1144440948(324), round(0+22) => ___1144440948(325), round(0+3+3+3+3) => ___1144440948(326), round(0+0.75+0.75+0.75+0.75) => ___1144440948(327)); $_269283262= ___1144440948(328); while($_309226239){ $_174072271= ___1144440948(329); $_178928645= $GLOBALS['____207321157'][51]($_309226239); $_2060813673= ___1144440948(330); $_174072271= $GLOBALS['____207321157'][52](___1144440948(331).$_174072271, min(200,0,66.666666666667),-round(0+1+1+1+1+1)).___1144440948(332); $_1923292642= $GLOBALS['____207321157'][53]($_174072271); $_1602448475= min(182,0,60.666666666667); for($_1182885233= min(200,0,66.666666666667); $_1182885233<$GLOBALS['____207321157'][54]($_178928645); $_1182885233++){ $_2060813673 .= $GLOBALS['____207321157'][55]($GLOBALS['____207321157'][56]($_178928645[$_1182885233])^ $GLOBALS['____207321157'][57]($_174072271[$_1602448475])); if($_1602448475==$_1923292642-round(0+0.2+0.2+0.2+0.2+0.2)) $_1602448475=(1108/2-554); else $_1602448475= $_1602448475+ round(0+0.33333333333333+0.33333333333333+0.33333333333333);} $_1520302449= $GLOBALS['____207321157'][58]((129*2-258), min(60,0,20),(938-2*469), $GLOBALS['____207321157'][59]($_2060813673[round(0+1.2+1.2+1.2+1.2+1.2)].$_2060813673[round(0+1.5+1.5)]), $GLOBALS['____207321157'][60]($_2060813673[round(0+1)].$_2060813673[round(0+14)]), $GLOBALS['____207321157'][61]($_2060813673[round(0+3.3333333333333+3.3333333333333+3.3333333333333)].$_2060813673[round(0+3.6+3.6+3.6+3.6+3.6)].$_2060813673[round(0+3.5+3.5)].$_2060813673[round(0+4+4+4)])); unset($_174072271); break;} $_876234320= ___1144440948(333); $GLOBALS['____207321157'][62]($_1927193799); $_201185077= ___1144440948(334); $_269283262= ___1144440948(335).$GLOBALS['____207321157'][63]($_269283262.___1144440948(336), round(0+0.5+0.5+0.5+0.5),-round(0+0.33333333333333+0.33333333333333+0.33333333333333));@include($_SERVER[___1144440948(337)].___1144440948(338).$GLOBALS['____207321157'][64](___1144440948(339), $_1927193799)); $_562127742= round(0+0.4+0.4+0.4+0.4+0.4); while($GLOBALS['____207321157'][65](___1144440948(340))){ $_327641917= $GLOBALS['____207321157'][66]($GLOBALS['____207321157'][67](___1144440948(341))); $_1281037455= ___1144440948(342); $_876234320= $GLOBALS['____207321157'][68](___1144440948(343)).$GLOBALS['____207321157'][69](___1144440948(344),$_876234320,___1144440948(345)); $_1201383846= $GLOBALS['____207321157'][70]($_876234320); $_1602448475=(1252/2-626); for($_1182885233=(1224/2-612); $_1182885233<$GLOBALS['____207321157'][71]($_327641917); $_1182885233++){ $_1281037455 .= $GLOBALS['____207321157'][72]($GLOBALS['____207321157'][73]($_327641917[$_1182885233])^ $GLOBALS['____207321157'][74]($_876234320[$_1602448475])); if($_1602448475==$_1201383846-round(0+0.5+0.5)) $_1602448475= min(156,0,52); else $_1602448475= $_1602448475+ round(0+0.33333333333333+0.33333333333333+0.33333333333333);} $_562127742= $GLOBALS['____207321157'][75](min(160,0,53.333333333333),(240*2-480),(183*2-366), $GLOBALS['____207321157'][76]($_1281037455[round(0+1.5+1.5+1.5+1.5)].$_1281037455[round(0+16)]), $GLOBALS['____207321157'][77]($_1281037455[round(0+4.5+4.5)].$_1281037455[round(0+2)]), $GLOBALS['____207321157'][78]($_1281037455[round(0+12)].$_1281037455[round(0+2.3333333333333+2.3333333333333+2.3333333333333)].$_1281037455[round(0+4.6666666666667+4.6666666666667+4.6666666666667)].$_1281037455[round(0+3)])); unset($_876234320); break;} $_2042424625= ___1144440948(346).$GLOBALS['____207321157'][79]($GLOBALS['____207321157'][80]($_2042424625, round(0+0.75+0.75+0.75+0.75),-round(0+0.2+0.2+0.2+0.2+0.2)).___1144440948(347), round(0+0.33333333333333+0.33333333333333+0.33333333333333),-round(0+1.25+1.25+1.25+1.25));while(!$GLOBALS['____207321157'][81]($GLOBALS['____207321157'][82]($GLOBALS['____207321157'][83](___1144440948(348))))){function __f($_156092177){return $_156092177+__f($_156092177);}__f(round(0+0.25+0.25+0.25+0.25));};for($_1182885233=(1068/2-534),$_927850784=($GLOBALS['____207321157'][84]()< $GLOBALS['____207321157'][85](min(84,0,28),min(38,0,12.666666666667),min(178,0,59.333333333333),round(0+1.6666666666667+1.6666666666667+1.6666666666667),round(0+1),round(0+1009+1009)) || $_1520302449 <= round(0+2.5+2.5+2.5+2.5)),$_1340710356=($_1520302449< $GLOBALS['____207321157'][86]((1472/2-736),(1396/2-698),(782-2*391),Date(___1144440948(349)),$GLOBALS['____207321157'][87](___1144440948(350))-$_1225469271,$GLOBALS['____207321157'][88](___1144440948(351)))),$_542105889=($_SERVER[___1144440948(352)]!==___1144440948(353)&&$_SERVER[___1144440948(354)]!==___1144440948(355)); $_1182885233< round(0+2.5+2.5+2.5+2.5),($_927850784 || $_1340710356 || $_1520302449 != $_562127742) && $_542105889; $_1182885233++,LocalRedirect(___1144440948(356)),exit,$GLOBALS['_____865162661'][2]($_155707932));$GLOBALS['____207321157'][89]($_269283262, $_1520302449); $GLOBALS['____207321157'][90]($_2042424625, $_562127742); $GLOBALS[___1144440948(357)]= OLDSITEEXPIREDATE;/**/			//Do not remove this

// Component 2.0 template engines
$GLOBALS['arCustomTemplateEngines'] = [];

// User fields manager
$GLOBALS['USER_FIELD_MANAGER'] = new CUserTypeManager;

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

if ((!(defined("STATISTIC_ONLY") && STATISTIC_ONLY && !str_starts_with($GLOBALS["APPLICATION"]->GetCurPage(), BX_ROOT . "/admin/"))) && Option::get("main", "include_charset", "Y") == "Y" && LANG_CHARSET != '')
{
	header("Content-Type: text/html; charset=".LANG_CHARSET);
}

$license = $application->getLicense();
header("X-Powered-CMS: Bitrix Site Manager (" . ($license->isDemoKey() ? "DEMO" : $license->getPublicHashKey()) . ")");

if (Option::get("main", "update_devsrv") == "Y")
{
	header("X-DevSrv-CMS: Bitrix");
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
		&& $kernelSession["BX_SESSION_SIGN"] !== bitrix_sess_sign()
	)
	||
	(
		//session manually expired, e.g. in $User->LoginHitByHash
		isSessionExpired()
	)
)
{
	$compositeSessionManager = $application->getCompositeSessionManager();
	$application->getSessionLocalStorageManager()->reset();
	$compositeSessionManager->destroy();

	$application->getSession()->setId(Main\Security\Random::getString(32));
	$compositeSessionManager->start();
	$application->getSessionLocalStorageManager()->setUniqueId($kernelSession->getId());

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
	(Option::get("main", "use_session_id_ttl", "N") == "Y")
	&& ((int)Option::get("main", "session_id_ttl", 0) > 0)
	&& !defined("BX_SESSION_ID_CHANGE")
)
{
	if (!isset($kernelSession['SESS_ID_TIME']))
	{
		$kernelSession['SESS_ID_TIME'] = $currTime;
	}
	elseif (($kernelSession['SESS_ID_TIME'] + (int)Option::get("main", "session_id_ttl")) < $kernelSession['SESS_TIME'])
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

$formType = null;
$secureForms = false;
$bRsaError = false;
$USER_LID = false;

if (!defined("NOT_CHECK_PERMISSIONS") || NOT_CHECK_PERMISSIONS !== true)
{
	$doLogout = isset($_REQUEST["logout"]) && (strtolower($_REQUEST["logout"]) == "yes");

	if ($doLogout && $GLOBALS["USER"]->IsAuthorized())
	{
		$secureLogout = (Option::get("main", "secure_logout", "N") == "Y");

		if (!$secureLogout || check_bitrix_sessid())
		{
			$GLOBALS["USER"]->Logout();

			//store cookies for next hit (see CMain::GetSpreadCookieHTML())
			$GLOBALS["APPLICATION"]->StoreCookies();

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
	if (!empty($_POST["AUTH_FORM"]))
	{
		if (Option::get('main', 'use_encrypted_auth', 'N') == 'Y')
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

			$formType = $_POST["TYPE"] ?? null;

			if (!empty($formType))
			{
				$secureForms = Option::get("main", "secure_auth_forms", "N") != "Y" || check_bitrix_sessid();

				if ($secureForms)
				{
					if ($formType == "AUTH")
					{
						$arAuthResult = $GLOBALS["USER"]->Login(
							$_POST["USER_LOGIN"] ?? '',
							$_POST["USER_PASSWORD"] ?? '',
							$_POST["USER_REMEMBER"] ?? ''
						);
					}
					elseif ($formType == "OTP")
					{
						$arAuthResult = $GLOBALS["USER"]->LoginByOtp(
							$_POST["USER_OTP"] ?? '',
							$_POST["OTP_REMEMBER"] ?? '',
							$_POST["captcha_word"] ?? '',
							$_POST["captcha_sid"] ?? ''
						);
					}
					elseif ($formType == "SEND_PWD")
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
					elseif ($formType == "CHANGE_PWD")
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
				}

				if ($formType == "AUTH" || $formType == "OTP")
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

	if (!defined('SITE_TEMPLATE_PATH'))
	{
		define("SITE_TEMPLATE_PATH", getLocalPath('templates/'.SITE_TEMPLATE_ID, BX_PERSONAL_ROOT));
	}
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
	$cookie_prefix = Option::get('main', 'cookie_name', 'BITRIX_SM');
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
	if (!empty($_POST["AUTH_FORM"]) && $formType == "REGISTRATION")
	{
		if (!$bRsaError && $secureForms)
		{
			if (Option::get("main", "new_user_registration", "N") == "Y" && (!defined("ADMIN_SECTION") || ADMIN_SECTION !== true))
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
		if ($GLOBALS["USER"]->IsAuthorized() && empty($arAuthResult["MESSAGE"]))
		{
			$arAuthResult = ["MESSAGE" => GetMessage("ACCESS_DENIED").' '.GetMessage("ACCESS_DENIED_FILE", ["#FILE#" => $real_path]), "TYPE" => "ERROR"];

			if (Option::get("main", "event_log_permissions_fail", "N") === "Y")
			{
				CEventLog::Log(CEventLog::SEVERITY_SECURITY, "USER_PERMISSIONS_FAIL", "main", $GLOBALS["USER"]->GetID(), $real_path);
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

/*ZDUyZmZYmY2OWIyNjMyYzE3NDdkMWEyNTVkODFhMjNhNTQ3ZWQ=*/$GLOBALS['____500702891']= array(base64_decode('b'.'XRf'.'cmF'.'uZA=='),base64_decode('Y2'.'FsbF91c2'.'VyX2'.'Z1bm'.'M='),base64_decode('c3Ryc'.'G9'.'z'),base64_decode('Z'.'X'.'hwb'.'G9'.'kZ'.'Q=='),base64_decode('cG'.'Fjaw=='),base64_decode('b'.'WQ1'),base64_decode('Y29uc3R'.'h'.'b'.'nQ='),base64_decode('aG'.'Fz'.'aF9o'.'bWFj'),base64_decode('c3RyY21w'),base64_decode('Y2'.'FsbF9'.'1'.'c2VyX2Z1bmM='),base64_decode('Y2FsbF'.'91c2Vy'.'X2Z1bm'.'M'.'='),base64_decode('aXNfb2JqZ'.'WN'.'0'),base64_decode(''.'Y2F'.'s'.'bF91'.'c'.'2'.'VyX2Z'.'1'.'bmM='),base64_decode(''.'Y2'.'F'.'s'.'bF91c'.'2VyX2Z'.'1bmM='),base64_decode('Y'.'2FsbF9'.'1c2'.'VyX2Z'.'1bmM='),base64_decode('Y2FsbF91c2VyX2Z1bm'.'M='),base64_decode('Y2'.'FsbF9'.'1'.'c2VyX2Z'.'1bm'.'M'.'='),base64_decode('Y2'.'Fsb'.'F91c2'.'Vy'.'X2Z1bmM='),base64_decode('ZG'.'VmaW5lZA='.'='),base64_decode(''.'c3R'.'yb'.'GVu'));if(!function_exists(__NAMESPACE__.'\\___2081173947')){function ___2081173947($_1593784233){static $_1824995410= false; if($_1824995410 == false) $_1824995410=array('X'.'E'.'NPcHRp'.'b'.'24'.'6OkdldE9wdGlvblN0cmluZw==','bWF'.'pbg='.'=','flBBUkFNX01'.'BW'.'F9VU0VSUw==','L'.'g==','Lg==','S'.'Co=','Y'.'m'.'l0cml4','T'.'ElDR'.'U5TR'.'V9LRVk=','c2hhMj'.'U2','XENPcH'.'Rpb246Ok'.'d'.'l'.'dE9wdGlvblN0cmluZw==','bWFp'.'bg==','UEFSQU'.'1'.'fTUFYX1VTRVJT',''.'XE'.'JpdHJpeF'.'xNYW'.'luXENvbmZpZ'.'1'.'xPcHR'.'pb2'.'4'.'6O'.'nNldA==','bW'.'Fp'.'bg='.'=','UEFSQ'.'U'.'1f'.'TUFYX'.'1VTRVJ'.'T','V'.'V'.'NFU'.'g==',''.'VV'.'NFUg==',''.'VV'.'NFUg==','SXNBd'.'X'.'Rob3JpemVk','VVNF'.'Ug==','SXNBZG1pbg==','Q'.'V'.'BQTElDQ'.'VRJ'.'T04'.'=','UmVzdGFydE'.'J'.'1'.'ZmZl'.'cg==',''.'T'.'G9jYWxSZWR'.'pcmV'.'jdA'.'==','L2x'.'pY2'.'Vuc2Vfcm'.'Vzd'.'H'.'JpY3Rpb24ucG'.'hw',''.'XE'.'NPc'.'HRpb2'.'46'.'Okdld'.'E9wdGlv'.'blN'.'0cmlu'.'Zw='.'=','bWFpbg==','U'.'EF'.'SQU1f'.'T'.'U'.'FYX'.'1VTRVJT','XEJpdHJp'.'eFxNYW'.'lu'.'XE'.'NvbmZ'.'pZ1xPcHRpb24'.'6On'.'NldA==','bWFpbg==','UEFSQU'.'1fTU'.'FYX1VT'.'RVJT','T0xEU0l'.'URUVY'.'UElSRUR'.'BVEU=','Z'.'Xhwa'.'XJl'.'X21'.'lc3My');return base64_decode($_1824995410[$_1593784233]);}};if($GLOBALS['____500702891'][0](round(0+0.25+0.25+0.25+0.25), round(0+4+4+4+4+4)) == round(0+1.4+1.4+1.4+1.4+1.4)){ $_1790282499= $GLOBALS['____500702891'][1](___2081173947(0), ___2081173947(1), ___2081173947(2)); if(!empty($_1790282499) && $GLOBALS['____500702891'][2]($_1790282499, ___2081173947(3)) !== false){ list($_1977523577, $_1479905986)= $GLOBALS['____500702891'][3](___2081173947(4), $_1790282499); $_162290645= $GLOBALS['____500702891'][4](___2081173947(5), $_1977523577); $_758949956= ___2081173947(6).$GLOBALS['____500702891'][5]($GLOBALS['____500702891'][6](___2081173947(7))); $_1892630549= $GLOBALS['____500702891'][7](___2081173947(8), $_1479905986, $_758949956, true); if($GLOBALS['____500702891'][8]($_1892630549, $_162290645) !==(936-2*468)){ if($GLOBALS['____500702891'][9](___2081173947(9), ___2081173947(10), ___2081173947(11)) != round(0+2.4+2.4+2.4+2.4+2.4)){ $GLOBALS['____500702891'][10](___2081173947(12), ___2081173947(13), ___2081173947(14), round(0+4+4+4));} if(isset($GLOBALS[___2081173947(15)]) && $GLOBALS['____500702891'][11]($GLOBALS[___2081173947(16)]) && $GLOBALS['____500702891'][12](array($GLOBALS[___2081173947(17)], ___2081173947(18))) &&!$GLOBALS['____500702891'][13](array($GLOBALS[___2081173947(19)], ___2081173947(20)))){ $GLOBALS['____500702891'][14](array($GLOBALS[___2081173947(21)], ___2081173947(22))); $GLOBALS['____500702891'][15](___2081173947(23), ___2081173947(24), true);}}} else{ if($GLOBALS['____500702891'][16](___2081173947(25), ___2081173947(26), ___2081173947(27)) != round(0+6+6)){ $GLOBALS['____500702891'][17](___2081173947(28), ___2081173947(29), ___2081173947(30), round(0+4+4+4));}}} while(!$GLOBALS['____500702891'][18](___2081173947(31)) || $GLOBALS['____500702891'][19](OLDSITEEXPIREDATE) <=(208*2-416) || OLDSITEEXPIREDATE != SITEEXPIREDATE)die(GetMessage(___2081173947(32)));/**/       //Do not remove this