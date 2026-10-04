<?php

namespace Bitrix\Crm\Automation\Trigger;

/**
 * Normalization of an address the event carries from the outside world: a clicked link, a form page.
 * Nothing escapes a RETURN value on its way into a robot's mail body, timeline comment or task text,
 * so only a relative path or an http(s) address of a sane length is published.
 */
trait ReturnUrlTrait
{
	private const RETURN_URL_MAX_LENGTH = 2000;

	protected static function buildReturnUrlValue(mixed $url): string
	{
		$url = is_string($url) ? trim($url) : '';

		if ($url === '' || mb_strlen($url) > self::RETURN_URL_MAX_LENGTH)
		{
			return '';
		}

		if (preg_match('#[\x00-\x20\x7F<>"\'`\\\\]#', $url))
		{
			return '';
		}

		return preg_match('#^(?:/(?!/)|https?://)#i', $url) ? $url : '';
	}
}
