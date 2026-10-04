<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2012 Bitrix
 */

namespace Bitrix\Main\Mail;

use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\EventResult;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\SystemException;

/**
 * Class Tracking
 * @package Bitrix\Main\Mail
 */
class Tracking
{
	const SIGN_SALT_ACTION = 'event_mail_tracking';

	const onRead = 'OnMailEventMailRead';
	const onClick = 'OnMailEventMailClick';
	const onUnsubscribe = 'OnMailEventSubscriptionDisable';
	const onChangeStatus = 'OnMailEventMailChangeStatus';
	const onClickRedirect = 'OnMailEventMailClickRedirect';
	const CUSTOM_SIGNER_KEY = 'signer_sender_mail_key';

	/**
	 * Get tag.
	 *
	 * @param string $moduleId Module ID.
	 * @param array $fields Fields.
	 * @return string
	 */
	public static function getTag($moduleId, $fields)
	{

		$moduleId = str_replace(".", "--", $moduleId);
		return $moduleId . "." . base64_encode(json_encode($fields));
	}

	/**
	 * Parse tag.
	 *
	 * @param string $tag Tag.
	 * @return array
	 */
	public static function parseTag($tag)
	{
		$data = explode(".", $tag);
		$moduleId = str_replace("--", ".", $data[0]);
		unset($data[0]);

		return array('MODULE_ID' => $moduleId, 'FIELDS' => (array) json_decode(base64_decode(implode('.', $data))));
	}

	/**
	 * Get signed tag.
	 *
	 * @param string $moduleId Module ID.
	 * @param array $fields Fields.
	 * @return string
	 * @throws \Bitrix\Main\ArgumentTypeException
	 */
	public static function getSignedTag($moduleId, $fields)
	{
		$tag = static::getTag($moduleId, $fields);
		$signer = new Signer;
		return $signer->sign($tag, static::SIGN_SALT_ACTION);
	}

	/**
	 * Parse signed tag.
	 *
	 * @param string $signedTag Signed tag.
	 * @return array
	 * @throws BadSignatureException
	 * @throws Main\ArgumentTypeException
	 */
	public static function parseSignedTag($signedTag)
	{
		try
		{
			$signer = new Signer;
			$unsignedTag = $signer->unsign($signedTag, static::SIGN_SALT_ACTION);
			return static::parseTag($unsignedTag);
		}
		catch (BadSignatureException $e)
		{
		}

		$signer->setKey(self::getSignKey());
		$unsignedTag = $signer->unsign($signedTag, static::SIGN_SALT_ACTION);
		return static::parseTag($unsignedTag);
	}

	/**
	 * Get read page link
	 *
	 * @param string $moduleId Module ID.
	 * @param array $fields Fields.
	 * @param string|null $urlPage Url of custom click page.
	 * @return string
	 * @throws SystemException
	 */
	public static function getLinkRead($moduleId, $fields, $urlPage = null)
	{
		return static::getTaggedLink(
			static::getTag($moduleId, $fields),
			'read',
			$urlPage
		);
	}

	/**
	 * Get click page link.
	 *
	 * @param string $moduleId Module ID.
	 * @param array $fields Fields.
	 * @param string|null $urlPage Url of custom click page.
	 * @return string
	 * @throws SystemException
	 */
	public static function getLinkClick($moduleId, $fields, $urlPage = null)
	{
		return static::getTaggedLink(
			static::getTag($moduleId, $fields),
			'click',
			$urlPage
		);
	}

	/**
	 * Get link for unsubscribe.
	 *
	 * @param string $moduleId Module ID.
	 * @param array $fields Fields.
	 * @param string|null $urlPage Url of custom unsubscribe page.
	 * @return string
	 * @throws Main\ArgumentTypeException
	 * @throws SystemException
	 */
	public static function getLinkUnsub($moduleId, $fields, $urlPage = null)
	{
		return static::getTaggedLink(
			static::getSignedTag($moduleId, $fields),
			'unsub',
			$urlPage
		);
	}

	/**
	 * @param $tag
	 * @param $opCode
	 * @param null $uri
	 * @return null|string
	 * @throws SystemException
	 */
	protected static function getTaggedLink($tag, $opCode, $uri = null)
	{
		if(!$uri)
		{
			$uri = Application::getInstance()->getPersonalRoot();
			$uri .= "/tools/track_mail_$opCode.php";
		}

		$uri = $uri . (!str_contains($uri, "?") ? "?" : "&");
		$uri .= 'tag=' . urlencode($tag);

		return $uri;
	}

	/**
	 * Get sign.
	 *
	 * @param string $value Value.
	 * @return string
	 * @throws Main\ArgumentTypeException
	 */
	public static function getSign($value)
	{
		static $cached = array();
		foreach ($cached as $cache)
		{
			if ($cache[0] == $value)
			{
				return $cache[1];
			}
		}

		$signer = new Signer;
		$sign = $signer->getSignature($value, static::SIGN_SALT_ACTION);

		$cached[] = array($value, $sign);
		if (count($cached) > 10)
		{
			array_shift($cached);
		}

		return $sign;
	}

	/**
	 * Verify sign.
	 *
	 * @param string $value Value.
	 * @param string $signature Signature.
	 * @return bool
	 */
	public static function validateSign($value, $signature)
	{
		try
		{
			$signer = new Signer;
			$result = $signer->validate($value, $signature, static::SIGN_SALT_ACTION);
		}
		catch (BadSignatureException $exception)
		{
			$result = false;
		}

		if(!$result)
		{
			return self::validateSignWithStoredKey($value, $signature);
		}

		return $result;
	}

	private static function validateSignWithStoredKey($value, $signature)
	{
		try
		{
			$signer = new Signer;
			$key = self::getSignKey();

			if (is_string($key))
			{
				$signer->setKey($key);
			}

			return $signer->validate($value, $signature, static::SIGN_SALT_ACTION);
		}
		catch (BadSignatureException $exception)
		{
			return false;
		}
	}

	private static function getSignKey()
	{
		$key = Config\Option::get('sender', self::CUSTOM_SIGNER_KEY, null);
		if (!$key)
		{
			$key = Config\Option::get('main', 'signer_default_key', null);
			if (is_string($key))
			{
				Config\Option::set('sender', self::CUSTOM_SIGNER_KEY, $key);
			}
		}

		return $key;
	}

	/**
	 * Get subscription list.
	 *
	 * @param array $data Data.
	 * @return array|bool
	 */
	public static function getSubscriptionList($data)
	{
		$subscription = array();

		if(array_key_exists('MODULE_ID', $data))
			$filter = array($data['MODULE_ID']);
		else
			$filter = null;

		if(!is_array($data['FIELDS'])) return false;

		$event = new Main\Event("main", "OnMailEventSubscriptionList", array($data['FIELDS']), $filter);
		$event->send();
		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() == EventResult::ERROR)
			{
				return false;
			}

			$subscriptionList = $eventResult->getParameters();
			if($subscriptionList && is_array($subscriptionList['LIST']))
			{
				$subscription = array_merge(
					$subscription,
					array($eventResult->getModuleId() => $subscriptionList['LIST'])
				);
			}
		}

		if (empty($data['MODULE_ID']) || $data['MODULE_ID'] === 'main')
		{
			if (empty($subscription['main']))
			{
				$subscription['main'] = [];
			}
			$subscription['main'] = array_merge(
				$subscription['main'],
				EventManager::onMailEventSubscriptionList($data)
			);
		}

		if(array_key_exists('MODULE_ID', $data))
			$subscription = $subscription[$data['MODULE_ID']];

		return $subscription;
	}

	/**
	 * Subscribe.
	 *
	 * @param array $data Data.
	 * @return bool
	 */
	public static function subscribe($data)
	{
		if(!is_array($data['FIELDS'])) return false;

		$event = new Main\Event("main", "OnMailEventSubscriptionEnable", array($data['FIELDS']), array($data['MODULE_ID']));
		$event->send();
		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() == EventResult::ERROR)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Unsubscribe.
	 *
	 * @param array $data Data.
	 * @return bool
	 */
	public static function unsubscribe($data)
	{
		if(!is_array($data['FIELDS'])) return false;

		$event = new Main\Event("main", "OnMailEventSubscriptionDisable", array($data['FIELDS']), array($data['MODULE_ID']));
		$event->send();
		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() == EventResult::ERROR)
			{
				return false;
			}
		}

		if (!empty($data['MODULE_ID']) && $data['MODULE_ID'] === 'main')
		{
			return EventManager::onMailEventSubscriptionDisable($data);
		}

		return true;
	}

	/**
	 * Click.
	 *
	 * @param array $data Data.
	 * @return bool
	 */
	public static function click(array $data)
	{
		if (Main\Config\Option::get('main', 'track_outgoing_emails_click', 'Y') != 'Y')
		{
			return false;
		}

		if(array_key_exists('MODULE_ID', $data))
			$filter = array($data['MODULE_ID']);
		else
			$filter = null;

		$event = new Main\Event("main", "OnMailEventMailClick", array($data['FIELDS']), $filter);
		$event->send();
		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() == EventResult::ERROR)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Track click from request.
	 *
	 * @return void
	 */
	public static function clickFromRequest()
	{
		$request = Main\Context::getCurrent()->getRequest();
		$url = $request->get('url');
		$sign = $request->get('sign');
		$tag = $request->get('tag');

		if ($tag)
		{
			try
			{
				$tag = static::parseTag($tag);
				$tag['FIELDS']['IP'] = $request->getRemoteAddress();
				$tag['FIELDS']['URL'] = $url;
				static::click($tag);
			}
			catch (SystemException $exception)
			{

			}
		}

		$isValidate = static::validateSign($url, $sign);
		$skipSecCheck = ($sign && $url && $isValidate);
		$url = $url ?: '/';
		if ($isValidate)
		{
			$url = static::applyClickRedirectHandlers($url, $skipSecCheck);
			LocalRedirect($url, $skipSecCheck);
		}
		else
		{
			ShowError('Failed to verify the security of the url address');
		}
	}

	/**
	 * Generic extension point invoked right before the click redirect.
	 * Owner modules (e.g. sender) may sanitize the target url — for instance strip their own
	 * service query parameters that must not leak to external sites — without main knowing about
	 * any concrete parameter. Handlers must only narrow the url within the same origin, never
	 * change the destination.
	 *
	 * Defense in depth: the caller's $skipSecCheck flag (which may disable the anti-open-redirect
	 * check) is computed against the ORIGINAL signed url and is NOT recomputed here. Therefore a
	 * handler-supplied url is applied only when its origin (scheme + host + port) is identical to
	 * the origin of the incoming url. If a handler changes the origin the change is ignored and the
	 * previous url is kept, so the destination stays the same and $skipSecCheck remains valid.
	 *
	 * @param string $url Target url (already signature-validated).
	 * @param bool $isSigned Whether the url arrived with a valid signature.
	 * @return string Possibly sanitized url with the original origin preserved.
	 */
	private static function applyClickRedirectHandlers(string $url, bool $isSigned): string
	{
		$originalOrigin = null;

		$event = new Main\Event(
			'main',
			self::onClickRedirect,
			[['url' => $url, 'isSigned' => $isSigned]]
		);
		$event->send();

		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() === EventResult::ERROR)
			{
				continue;
			}

			$parameters = $eventResult->getParameters();
			if (is_array($parameters) && isset($parameters['url']) && is_string($parameters['url']) && $parameters['url'] !== '')
			{
				$candidate = $parameters['url'];
				// Apply the handler's url only when it keeps the original origin. A different origin
				// would redirect elsewhere while $skipSecCheck (computed for the original url) still
				// bypasses the anti-open-redirect check, so such a change must be discarded.
				// $originalOrigin is computed lazily, only once there is an actual candidate to compare
				// against, so the common case (no handler returns a url) never parses $url at all.
				if ($originalOrigin === null)
				{
					$originalOrigin = static::getUrlOrigin($url);
				}

				if (static::getUrlOrigin($candidate) === $originalOrigin)
				{
					$url = $candidate;
				}
			}
		}

		return $url;
	}

	/**
	 * Builds a normalized origin key (scheme + host + port) used to compare a handler-supplied url
	 * against the original redirect target. Two urls with an equal key point to the same origin.
	 *
	 * Relative urls (no authority) collapse to a single stable marker (empty string), so any two
	 * relative urls share an origin while a relative-to-absolute switch does not.
	 *
	 * Protocol-relative forms ("//host", and the backslash variants "/\", "\/", "\\" that browsers
	 * treat as "//") are detected before normalization — Main\Web\Uri collapses the leading pair
	 * into "/" and hides the host — and reparsed with a temporary scheme, matching the approach used
	 * by the redirect handler (see sender ExternalUrlSanitizer). The same temporary scheme is used
	 * for every protocol-relative url, so an original protocol-relative url and a handler-sanitized
	 * protocol-relative variant of it yield the same origin.
	 *
	 * @param string $url Url to derive the origin from.
	 * @return string Origin key "scheme://host:port" (lowercased) or '' for relative urls.
	 */
	private static function getUrlOrigin(string $url): string
	{
		$trimmedUrl = ltrim($url);
		$isProtocolRelative = strlen($trimmedUrl) >= 2
			&& ($trimmedUrl[0] === '/' || $trimmedUrl[0] === '\\')
			&& ($trimmedUrl[1] === '/' || $trimmedUrl[1] === '\\');

		if ($isProtocolRelative)
		{
			// Turn backslashes after the leading pair into forward slashes for this temporary parsing url:
			// Main\Web\Uri does not treat "\" as a path delimiter, so a host like "portal.example\path"
			// would otherwise be glued to the path and produce a wrong origin key.
			$uri = new Main\Web\Uri('http://' . str_replace('\\', '/', substr($trimmedUrl, 2)));
		}
		else
		{
			// Normalize backslashes to forward slashes for this temporary parsing url ONLY, to
			// extract the origin: Main\Web\Uri does not treat "\" as a path delimiter, so a host like
			// "portal.example\path" would otherwise be glued to the path and produce a wrong origin
			// key. The scheme is unaffected (it precedes "://"), so the real scheme is still used.
			$uri = new Main\Web\Uri(str_replace('\\', '/', $url));
		}

		$host = mb_strtolower($uri->getHost());
		if ($host === '')
		{
			return '';
		}

		$scheme = mb_strtolower($uri->getScheme());
		$port = $uri->getPort();

		return $scheme . '://' . $host . ':' . ($port === null ? '' : $port);
	}

	/**
	 * Track read from request.
	 *
	 * @return bool
	 */
	public static function readFromRequest()
	{
		$request = Main\Context::getCurrent()->getRequest();
		$tag = $request->get('tag');
		if (!$tag)
		{
			return false;
		}

		try
		{
			$data = static::parseTag($tag);
			$data['FIELDS']['IP'] = $request->getRemoteAddress();
			return static::read($data);
		}
		catch (SystemException $exception)
		{
			return false;
		}
	}

	/**
	 * Read.
	 *
	 * @param array $data Data.
	 * @return bool
	 */
	public static function read(array $data)
	{
		if (Main\Config\Option::get('main', 'track_outgoing_emails_read', 'Y') != 'Y')
		{
			return false;
		}

		if(array_key_exists('MODULE_ID', $data))
			$filter = array($data['MODULE_ID']);
		else
			$filter = null;

		$event = new Main\Event("main", "OnMailEventMailRead", array($data['FIELDS']), $filter);
		$event->send();
		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() == EventResult::ERROR)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Change status of sending.
	 *
	 * @param Callback\Result $callbackResult Callback result instance.
	 * @return bool
	 */
	public static function changeStatus(Callback\Result $callbackResult)
	{
		if($callbackResult->getModuleId())
		{
			$filter = [$callbackResult->getModuleId()];
		}
		else
		{
			$filter = null;
		}

		$event = new Main\Event("main", self::onChangeStatus, [$callbackResult], $filter);
		$event->send();
		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() == EventResult::ERROR)
			{
				return false;
			}
		}

		return true;
	}
}
