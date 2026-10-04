<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

/**
 * Grammar of an address of the new service instance.
 *
 * The value is two things at once: the destination a JWT is sent to and a JS string literal of the
 * editor page. A quote inside the value would close that literal, so the address is checked here,
 * where it is written and read, rather than at either sink.
 *
 * Both http and https are accepted: the pilot runs inside a controlled contour that speaks http, and
 * the token travelling in the clear there is an accepted risk of stage 1.
 */
final class ServiceAddress
{
	private const MAX_LENGTH = 255;

	// Quotes and backslashes break out of the JS literal of the editor template, angle brackets out of
	// the markup around it, and everything up to a space covers control characters and line breaks.
	private const FORBIDDEN_CHARACTERS = '/[\x00-\x20\x7F"\'`<>\\\\]/';

	private const HOST = '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/i';

	private const PATH = '#^(/[a-z0-9._~\-]+)+$#i';

	public static function normalize(string $value): string
	{
		return rtrim(trim($value), '/');
	}

	/**
	 * @param string $value normalised address, http(s)://host[:port][/path]
	 */
	public static function isValid(string $value): bool
	{
		if ($value === '' || strlen($value) > self::MAX_LENGTH)
		{
			return false;
		}

		if (preg_match(self::FORBIDDEN_CHARACTERS, $value) === 1)
		{
			return false;
		}

		$parts = parse_url($value);
		if (!is_array($parts))
		{
			return false;
		}

		// parse_url() keeps the case of the scheme, and a scheme is case insensitive by the RFC.
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https')
		{
			return false;
		}

		// Credentials, a query and a fragment have no meaning for a service address, and each of them
		// is a way to smuggle a payload past a check that only looks at the host.
		if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))
		{
			return false;
		}

		if (preg_match(self::HOST, (string)($parts['host'] ?? '')) !== 1)
		{
			return false;
		}

		$port = $parts['port'] ?? null;
		if ($port !== null && ($port < 1 || $port > 65535))
		{
			return false;
		}

		$path = (string)($parts['path'] ?? '');

		return $path === '' || preg_match(self::PATH, $path) === 1;
	}
}
