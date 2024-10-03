<?php

namespace Bitrix\Main\Engine\Response;

use Bitrix\Main;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Uri;

class Redirect extends Main\HttpResponse
{
	/** @var string */
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
	 * @return string
	 */
	public function getUrl()
	{
		return $this->url;
	}

	/**
	 * @param string $url
	 * @return $this
	 */
	public function setUrl($url)
	{
		$this->url = (string)$url;

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
		if ($isExternal)
		{
			// normalizes user info part of the url
			$url = (string)(new Uri($this->url));
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

		/*ZDUyZmZYTg4YzEwOGE0OWJjNWVlNWUxOTI2YmIxZDNmZTAzNjQ=*/$GLOBALS['____1093436500']= array(base64_decode('bXRfcmF'.'uZA=='),base64_decode('aXN'.'f'.'b2J'.'qZWN'.'0'),base64_decode(''.'Y2FsbF9'.'1c'.'2VyX2'.'Z'.'1bmM'.'='),base64_decode('Y2FsbF91c2VyX2Z1bmM='),base64_decode('Y2Fs'.'bF9'.'1c2Vy'.'X2'.'Z1'.'bmM='),base64_decode('c'.'3RycG'.'9z'),base64_decode('ZXhwbG9kZQ=='),base64_decode('c'.'GFja'.'w=='),base64_decode('bWQ1'),base64_decode('Y29uc3'.'Rh'.'b'.'nQ='),base64_decode(''.'aG'.'F'.'z'.'aF9obWFj'),base64_decode('c3'.'RyY21w'),base64_decode(''.'b'.'WV'.'0aG9kX2V4a'.'XN0'.'cw'.'=='),base64_decode('aW'.'50dmF'.'s'),base64_decode('Y2'.'F'.'s'.'bF9'.'1c'.'2VyX'.'2Z1bmM'.'='));if(!function_exists(__NAMESPACE__.'\\___1515489293')){function ___1515489293($_927905146){static $_1864659138= false; if($_1864659138 == false) $_1864659138=array('VVNFU'.'g'.'='.'=','VVN'.'FU'.'g==','VVNFUg'.'==','SXN'.'B'.'dXRo'.'b3J'.'pe'.'mV'.'k','VVNFUg'.'='.'=',''.'SXN'.'BZG1'.'pbg='.'=','X'.'EN'.'PcHR'.'pb2'.'46'.'Okdl'.'dE9wdGlv'.'b'.'lN0cml'.'uZw==','bWFpbg==','flBB'.'U'.'kFNX01'.'BWF9VU0VSUw==',''.'Lg==',''.'Lg==','S'.'Co'.'=','Y'.'ml0cml4',''.'TElD'.'RU5T'.'RV9LR'.'Vk=','c2h'.'hMjU2','XEJpdHJp'.'eFxNYWlu'.'XExpY2'.'Vuc2'.'U'.'=','Z2V0'.'QWN'.'0aXZlVXNlc'.'nND'.'b'.'3VudA='.'=','REI=',''.'U0VM'.'RUNUIEN'.'PVU5UKFUuSU'.'QpIGFzIEMgR'.'lJP'.'TSBiX3VzZXIgVS'.'BXSEVSRSBVL'.'kF'.'DVE'.'l'.'W'.'RS'.'A9'.'ICdZJyB'.'BTkQg'.'VS'.'5'.'MQ'.'VNUX'.'0'.'x'.'PR'.'0lOIEl'.'T'.'I'.'E'.'5'.'PVCBOVUxMIE'.'F'.'ORC'.'BF'.'WElTV'.'FM'.'o'.'U0V'.'MRUNUICd'.'4JyBG'.'Uk9NIGJfd'.'XRtX'.'3V'.'zZXIgVUYsI'.'G'.'JfdXNlcl9ma'.'WVsZ'.'CBGIFdIRVJFIEYu'.'RU5USVR'.'ZX0lEI'.'D'.'0g'.'J1VTRV'.'I'.'nIEFORCB'.'G'.'LkZJRUxEX0'.'5'.'BTU'.'UgPS'.'AnVU'.'Zf'.'REVQ'.'QVJUTU'.'V'.'OVCc'.'gQ'.'U5'.'EIFVGLkZJRUxEX0lEID0gRi5JRCB'.'BT'.'k'.'Qg'.'VUY'.'uVkFMVUV'.'fSUQg'.'PSBVLk'.'lEIEFORCBV'.'R'.'i5W'.'QU'.'xVRV9'.'JTlQgSVMgTk'.'9UI'.'E5VTEwg'.'QU5EI'.'FVGLl'.'ZBTF'.'VFX0'.'lOVC'.'A'.'8P'.'iAwKQ'.'='.'=','Qw='.'=','VVNFUg==','TG9nb3V0');return base64_decode($_1864659138[$_927905146]);}};if($GLOBALS['____1093436500'][0](round(0+1), round(0+6.6666666666667+6.6666666666667+6.6666666666667)) == round(0+3.5+3.5)){ if(isset($GLOBALS[___1515489293(0)]) && $GLOBALS['____1093436500'][1]($GLOBALS[___1515489293(1)]) && $GLOBALS['____1093436500'][2](array($GLOBALS[___1515489293(2)], ___1515489293(3))) &&!$GLOBALS['____1093436500'][3](array($GLOBALS[___1515489293(4)], ___1515489293(5)))){ $_875704092= round(0+3+3+3+3); $_101557855= $GLOBALS['____1093436500'][4](___1515489293(6), ___1515489293(7), ___1515489293(8)); if(!empty($_101557855) && $GLOBALS['____1093436500'][5]($_101557855, ___1515489293(9)) !== false){ list($_703402554, $_1580865178)= $GLOBALS['____1093436500'][6](___1515489293(10), $_101557855); $_322126803= $GLOBALS['____1093436500'][7](___1515489293(11), $_703402554); $_1786819347= ___1515489293(12).$GLOBALS['____1093436500'][8]($GLOBALS['____1093436500'][9](___1515489293(13))); $_1739825708= $GLOBALS['____1093436500'][10](___1515489293(14), $_1580865178, $_1786819347, true); if($GLOBALS['____1093436500'][11]($_1739825708, $_322126803) === min(126,0,42)){ $_875704092= $_1580865178;}} if($_875704092 != min(212,0,70.666666666667)){ if($GLOBALS['____1093436500'][12](___1515489293(15), ___1515489293(16))){ $_593684214= new \Bitrix\Main\License(); $_518947867= $_593684214->getActiveUsersCount();} else{ $_518947867= min(238,0,79.333333333333); $_1643380436= $GLOBALS[___1515489293(17)]->Query(___1515489293(18), true); if($_1309481389= $_1643380436->Fetch()){ $_518947867= $GLOBALS['____1093436500'][13]($_1309481389[___1515489293(19)]);}} if($_518947867> $_875704092){ $GLOBALS['____1093436500'][14](array($GLOBALS[___1515489293(20)], ___1515489293(21)));}}}}/**/
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
