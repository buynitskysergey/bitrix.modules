<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

/**
 * Frame policy of the board editor page.
 *
 * Emitted directly with replace = false: {@see \Bitrix\Main\HttpResponse::flushHeader()} always
 * replaces same-name headers and would evict the policy set by the security module.
 */
final class FramePolicy
{
	public const CSP_HEADER = 'Content-Security-Policy';
	public const CSP_VALUE = "frame-ancestors 'self'";
	public const XFO_HEADER = 'X-Frame-Options';
	public const XFO_VALUE = 'SAMEORIGIN';

	/**
	 * @param string[] $sentHeaders Values as returned by headers_list(): "Name: value".
	 *
	 * @return array<int, array{name: string, value: string, replace: bool}>|null null means fail closed.
	 */
	public static function resolveHeaders(array $sentHeaders, bool $headersAlreadySent): ?array
	{
		if ($headersAlreadySent)
		{
			return null;
		}

		$headers = [
			['name' => self::CSP_HEADER, 'value' => self::CSP_VALUE, 'replace' => false],
		];

		// Conflicting values of the same header are treated as a deny and would break the portal's own frame.
		if (!self::hasHeader($sentHeaders, self::XFO_HEADER))
		{
			$headers[] = ['name' => self::XFO_HEADER, 'value' => self::XFO_VALUE, 'replace' => false];
		}

		return $headers;
	}

	/**
	 * @return bool false means the editor page must not be rendered.
	 */
	public static function emit(): bool
	{
		$headers = self::resolveHeaders(headers_list(), headers_sent());
		if ($headers === null)
		{
			return false;
		}

		foreach ($headers as $header)
		{
			header($header['name'] . ': ' . $header['value'], $header['replace']);
		}

		return true;
	}

	/**
	 * @param string[] $sentHeaders
	 */
	private static function hasHeader(array $sentHeaders, string $name): bool
	{
		$prefix = mb_strtolower($name) . ':';

		foreach ($sentHeaders as $header)
		{
			if (str_starts_with(mb_strtolower(trim($header)), $prefix))
			{
				return true;
			}
		}

		return false;
	}
}
