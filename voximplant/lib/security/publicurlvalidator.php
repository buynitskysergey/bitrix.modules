<?php

namespace Bitrix\Voximplant\Security;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Validates that a URL points only to globally routable (public) addresses.
 * Used to protect untrusted record download URLs against SSRF.
 */
class PublicUrlValidator
{
	public const ERROR_CODE = 'PRIVATE_IP';
	public const ERROR_CODE_INVALID_URL = 'INVALID_URL';
	public const ERROR_CODE_SERVER_NOT_AVAILABLE = 'SERVER_NOT_AVAILABLE';

	/**
	 * Checks that the host of the given URL resolves exclusively to globally
	 * routable addresses (both IPv4 and IPv6 are considered). The check is
	 * fail-closed: a non-http(s) scheme or a single non-global address makes
	 * the URL non-public. Errors carry the code INVALID_URL for malformed URLs
	 * and PRIVATE_IP for genuinely non-public addresses. An unresolvable host
	 * carries the transient, retryable code SERVER_NOT_AVAILABLE: no request is
	 * sent, so the SSRF invariant holds, and a later retry re-runs the full
	 * validation.
	 *
	 * On success the result carries the validated address to pin the connection
	 * to (Data: ['ip' => string]), so the caller can connect straight to it
	 * without a second, unchecked DNS resolution.
	 */
	public static function validate(string $url): Result
	{
		$result = new Result();

		$parsedUrl = parse_url($url);
		if (!is_array($parsedUrl))
		{
			return $result->addError(self::createInvalidUrlError('Record URL could not be parsed.'));
		}

		$scheme = isset($parsedUrl['scheme']) ? strtolower($parsedUrl['scheme']) : '';
		if ($scheme !== 'http' && $scheme !== 'https')
		{
			return $result->addError(self::createInvalidUrlError('Record URL scheme must be http or https.'));
		}

		$host = isset($parsedUrl['host']) ? trim($parsedUrl['host'], '[]') : '';
		if ($host === '')
		{
			return $result->addError(self::createInvalidUrlError('Record URL host is empty.'));
		}

		// Same condition as \Bitrix\Main\Web\Uri::convertToPunycode(): an IDN
		// host must be converted to punycode before DNS resolution.
		if (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/[^a-z0-9.-]/i', $host))
		{
			$host = static::punycodeHost($host);
			if ($host === null)
			{
				return $result->addError(
					self::createInvalidUrlError('Record URL host could not be converted to punycode.')
				);
			}
		}

		$addresses = self::collectAddresses($host);
		if (empty($addresses))
		{
			return $result->addError(
				self::createServerNotAvailableError('Record URL host could not be resolved to any address.')
			);
		}

		foreach ($addresses as $address)
		{
			if (!static::isGloballyRoutable($address))
			{
				return $result->addError(
					self::createPrivateIpError('Record URL resolves to a non-public address and was blocked.')
				);
			}
		}

		$result->setData(['ip' => $addresses[0]]);

		return $result;
	}

	/**
	 * True only for globally routable (public) addresses, as defined by RFC 6890
	 * (the ranges carrying the Global attribute). Relies on the
	 * FILTER_FLAG_GLOBAL_RANGE classifier, which covers both IPv4 and IPv6
	 * special-purpose ranges: loopback, private, reserved, CGNAT, IPv4-mapped
	 * IPv6 (::ffff:0:0/96) and the IPv6-only special blocks such as 2001:2::/48,
	 * 100::/64 and 2001::/23. Available as of PHP 8.2.0, matching the project's
	 * minimum supported version. Fail-closed: anything not proven globally
	 * routable is treated as non-public.
	 */
	protected static function isGloballyRoutable(string $address): bool
	{
		return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
	}

	/**
	 * Returns the list of IP addresses the host points to. IP literals are
	 * returned as is; host names are resolved into both A and AAAA records.
	 *
	 * @return string[]
	 */
	protected static function collectAddresses(string $host): array
	{
		if (filter_var($host, FILTER_VALIDATE_IP) !== false)
		{
			return [$host];
		}

		return static::resolveHost($host);
	}

	/**
	 * Converts an IDN host to its punycode form via the core converter.
	 * Returns null when the conversion fails. Extracted so unit tests can
	 * stub it: \CBXPunycode is unavailable without the kernel.
	 */
	protected static function punycodeHost(string $host): ?string
	{
		$encodingErrors = [];
		$asciiHost = \CBXPunycode::ToASCII($host, $encodingErrors);

		// ToASCII() may return false with no recorded errors (e.g. a host made
		// solely of ignorable code points collapses to an empty string), so the
		// non-string result must be rejected explicitly to keep the ?string contract.
		return empty($encodingErrors) && is_string($asciiHost) && $asciiHost !== '' ? $asciiHost : null;
	}

	/**
	 * Resolves a host name into IPv4 (A) and IPv6 (AAAA) addresses.
	 *
	 * @return string[]
	 */
	protected static function resolveHost(string $host): array
	{
		$addresses = [];

		$ipv4 = gethostbynamel($host);
		if (is_array($ipv4))
		{
			$addresses = array_merge($addresses, $ipv4);
		}

		$records = @dns_get_record($host, DNS_AAAA);
		if (is_array($records))
		{
			foreach ($records as $record)
			{
				if (!empty($record['ipv6']))
				{
					$addresses[] = $record['ipv6'];
				}
			}
		}

		return $addresses;
	}

	private static function createPrivateIpError(string $message): Error
	{
		return new Error($message, self::ERROR_CODE);
	}

	private static function createInvalidUrlError(string $message): Error
	{
		return new Error($message, self::ERROR_CODE_INVALID_URL);
	}

	private static function createServerNotAvailableError(string $message): Error
	{
		return new Error($message, self::ERROR_CODE_SERVER_NOT_AVAILABLE);
	}
}
