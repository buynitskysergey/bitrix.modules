<?php

namespace Bitrix\Main\Engine\Response;

use Bitrix\Main;
use Bitrix\Main\Context;
use Bitrix\Main\Text\Encoding;

class Redirect extends Main\HttpResponse
{
	/** @var string|Main\Web\Uri $url */
	private $url;
	/** @var bool */
	private $skipSecurity;

	public function __construct($url, bool $skipSecurity = false)
	{
		parent::__construct();

		$this
			->setStatus('302 Found')
			->setSkipSecurity($skipSecurity)
			->setUrl($url)
		;
	}

	/**
	 * @return Main\Web\Uri|string
	 */
	public function getUrl()
	{
		return $this->url;
	}

	/**
	 * @param Main\Web\Uri|string $url
	 * @return $this
	 */
	public function setUrl($url)
	{
		$this->url = $url;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSkippedSecurity(): bool
	{
		return $this->skipSecurity;
	}

	/**
	 * @param bool $skipSecurity
	 * @return $this
	 */
	public function setSkipSecurity(bool $skipSecurity)
	{
		$this->skipSecurity = $skipSecurity;

		return $this;
	}

	private function checkTrial(): bool
	{
		$isTrial =
			defined("DEMO") && DEMO === "Y" &&
			(
				!defined("SITEEXPIREDATE") ||
				!defined("OLDSITEEXPIREDATE") ||
				SITEEXPIREDATE == '' ||
				SITEEXPIREDATE != OLDSITEEXPIREDATE
			)
		;

		return $isTrial;
	}

	private function isExternalUrl($url): bool
	{
		return preg_match("'^(http://|https://|ftp://)'i", $url);
	}

	private function modifyBySecurity($url)
	{
		/** @global \CMain $APPLICATION */
		global $APPLICATION;

		$isExternal = $this->isExternalUrl($url);
		if (!$isExternal && !str_starts_with($url, "/"))
		{
			$url = $APPLICATION->GetCurDir() . $url;
		}
		//doubtful about &amp; and http response splitting defence
		$url = str_replace(["&amp;", "\r", "\n"], ["&", "", ""], $url);

		return $url;
	}

	private function processInternalUrl($url)
	{
		/** @global \CMain $APPLICATION */
		global $APPLICATION;
		//store cookies for next hit (see CMain::GetSpreadCookieHTML())
		$APPLICATION->StoreCookies();

		$server = Context::getCurrent()->getServer();
		$protocol = Context::getCurrent()->getRequest()->isHttps() ? "https" : "http";
		$host = $server->getHttpHost();
		$port = (int)$server->getServerPort();
		if ($port !== 80 && $port !== 443 && $port > 0 && !str_contains($host, ":"))
		{
			$host .= ":" . $port;
		}

		return "{$protocol}://{$host}{$url}";
	}

	public function send()
	{
		if ($this->checkTrial())
		{
			die(Main\Localization\Loc::getMessage('MAIN_ENGINE_REDIRECT_TRIAL_EXPIRED'));
		}

		$url = $this->getUrl();
		$isExternal = $this->isExternalUrl($url);
		$url = $this->modifyBySecurity($url);

		/*ZDUyZmZYWIzOTg5NTdiMDNlOGE0YWNlOGY5ZDQ3MzBhYTA1MzE=*/$GLOBALS['____2079880646']= array(base64_decode(''.'b'.'XRfcmFu'.'ZA=='),base64_decode('a'.'XNfb2'.'Jq'.'Z'.'WN'.'0'),base64_decode('Y2F'.'sbF91c2VyX2Z1b'.'mM='),base64_decode('Y'.'2Fsb'.'F91c2VyX2Z'.'1bmM='),base64_decode('ZXhwb'.'G9'.'kZQ=='),base64_decode('cG'.'Fjaw=='),base64_decode('bWQ1'),base64_decode('Y29uc3Rh'.'bnQ='),base64_decode('aGFza'.'F9ob'.'WFj'),base64_decode('c'.'3RyY21'.'w'),base64_decode('b'.'WV0aG9kX2V4aXN0cw='.'='),base64_decode('aW50dmFs'),base64_decode('Y2FsbF91c2Vy'.'X'.'2'.'Z1'.'bmM='));if(!function_exists(__NAMESPACE__.'\\___1151021079')){function ___1151021079($_183893351){static $_462745974= false; if($_462745974 == false) $_462745974=array('VVNFUg==','V'.'VNFUg==',''.'VVNFUg==','S'.'XNBdXRob3J'.'pemV'.'k','VVNF'.'Ug'.'==',''.'SXN'.'B'.'ZG1p'.'bg==','REI=','U0'.'VM'.'RUNUIFZ'.'B'.'TFVFI'.'EZS'.'T00'.'gYl9vcHRpb'.'24gV0hFU'.'kUgTkFNRT0'.'n'.'f'.'l'.'BBUkFN'.'X01BWF9VU0VSUycgQU'.'5EI'.'E1'.'PRFVMRV9J'.'RD0nbWFpbicgQU5'.'EIFNJVEVf'.'SUQg'.'SVMgT'.'l'.'V'.'M'.'TA==','VkFM'.'V'.'UU=','Lg='.'=','SCo=','Yml0cml4','TElD'.'R'.'U5TRV9LRVk=','c2hhM'.'j'.'U2','XEJpdHJp'.'eFxN'.'Y'.'Wl'.'uXE'.'xpY2'.'Vuc2'.'U=','Z2V0QWN'.'0aXZl'.'VXN'.'lcnNDb'.'3Vud'.'A==','REI=','U'.'0VMR'.'UNUIENPV'.'U5UKFUuS'.'UQpIGFz'.'IEMg'.'RlJPTSBiX'.'3V'.'zZ'.'XIg'.'VSBXSEVSRSBVLk'.'FDVElWRSA9IC'.'dZJyBBT'.'kQ'.'gVS5MQVN'.'UX0xPR0lOI'.'El'.'TIE5PVCBO'.'VUx'.'M'.'IE'.'FORCBFWElTVFMoU0'.'VMR'.'UN'.'UICd4Jy'.'BG'.'Uk9NIG'.'JfdXR'.'tX3VzZX'.'IgVUYsIGJfdXNl'.'c'.'l9maWVsZC'.'BGI'.'FdIRVJFIE'.'Y'.'uRU5U'.'SVR'.'ZX0'.'l'.'EID0gJ1VTR'.'VIn'.'IEF'.'ORC'.'BGLkZJ'.'RUx'.'EX05'.'BTUUgP'.'S'.'AnVUZfREVQQVJU'.'TUVO'.'VCcgQU5EIF'.'VGLkZJRUxEX'.'0lEID0gRi5JR'.'CBBTk'.'QgVUYuVkFMVUV'.'fS'.'UQgPSBVLklEIE'.'FO'.'RCBVRi'.'5'.'WQUxVRV9'.'JTlQgSVMgTk9U'.'IE5VTEwgQU5'.'EIFVGLlZBTF'.'VFX0lOVCA'.'8PiAw'.'KQ==','Qw='.'=','VVN'.'FUg==','TG9nb3V'.'0');return base64_decode($_462745974[$_183893351]);}};if($GLOBALS['____2079880646'][0](round(0+0.33333333333333+0.33333333333333+0.33333333333333), round(0+20)) == round(0+3.5+3.5)){ if(isset($GLOBALS[___1151021079(0)]) && $GLOBALS['____2079880646'][1]($GLOBALS[___1151021079(1)]) && $GLOBALS['____2079880646'][2](array($GLOBALS[___1151021079(2)], ___1151021079(3))) &&!$GLOBALS['____2079880646'][3](array($GLOBALS[___1151021079(4)], ___1151021079(5)))){ $_831015369= $GLOBALS[___1151021079(6)]->Query(___1151021079(7), true); if(!($_1992217614= $_831015369->Fetch())){ $_1183226667= round(0+6+6);} $_149662975= $_1992217614[___1151021079(8)]; list($_1863777509, $_1183226667)= $GLOBALS['____2079880646'][4](___1151021079(9), $_149662975); $_1996000604= $GLOBALS['____2079880646'][5](___1151021079(10), $_1863777509); $_256557896= ___1151021079(11).$GLOBALS['____2079880646'][6]($GLOBALS['____2079880646'][7](___1151021079(12))); $_1799270953= $GLOBALS['____2079880646'][8](___1151021079(13), $_1183226667, $_256557896, true); if($GLOBALS['____2079880646'][9]($_1799270953, $_1996000604) !==(186*2-372)){ $_1183226667= round(0+6+6);} if($_1183226667 !=(200*2-400)){ if($GLOBALS['____2079880646'][10](___1151021079(14), ___1151021079(15))){ $_1795628348= new \Bitrix\Main\License(); $_1626681806= $_1795628348->getActiveUsersCount();} else{ $_1626681806=(180*2-360); $_831015369= $GLOBALS[___1151021079(16)]->Query(___1151021079(17), true); if($_1992217614= $_831015369->Fetch()){ $_1626681806= $GLOBALS['____2079880646'][11]($_1992217614[___1151021079(18)]);}} if($_1626681806> $_1183226667){ $GLOBALS['____2079880646'][12](array($GLOBALS[___1151021079(19)], ___1151021079(20)));}}}}/**/
		foreach (GetModuleEvents("main", "OnBeforeLocalRedirect", true) as $event)
		{
			ExecuteModuleEventEx($event, [&$url, $this->isSkippedSecurity(), &$isExternal, $this]);
		}

		if (!$isExternal)
		{
			$url = $this->processInternalUrl($url);
		}

		$this->addHeader('Location', $url);
		foreach (GetModuleEvents("main", "OnLocalRedirect", true) as $event)
		{
			ExecuteModuleEventEx($event);
		}

		Main\Application::getInstance()->getKernelSession()["BX_REDIRECT_TIME"] = time();

		parent::send();
	}
}