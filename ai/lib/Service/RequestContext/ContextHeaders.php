<?php

declare(strict_types=1);

namespace Bitrix\AI\Service\RequestContext;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\JWT;

/**
 * Attribution context for an outbound AI request: who asks, from which portal, at which
 * service class — signed once and attached to the HTTP client as headers.
 *
 * A decorator over a ready client, like {@see \Bitrix\AI\Service\BasicAuthToken}: it never
 * touches the request body and never throws, because a request that cannot carry context
 * must still go out. The computed service class is returned so that a send point can put
 * the number into its body without computing the class a second time.
 *
 * Nobody verifies the signature yet — that is a separate, deliberately deferred task.
 */
final class ContextHeaders
{
	public const HEADER_SIGNED_CONTEXT = 'X-Signed-Context';
	public const HEADER_FEATURE = 'X-Cuet-Feature';

	public const OPTION_SECRET = 'cuet_context_secret';

	private const AUDIENCE = 'cuet';
	private const TOKEN_TTL = 3600;
	private const LOGGER_ID = 'ai.RequestContext';

	/** Receiver's own cap on the header value; a longer token is dropped, not truncated. */
	private const MAX_TOKEN_BYTES = 1024;

	public const FEATURE_MAX_LENGTH = 64;

	/**
	 * Attaches the context headers to a client and reports the service class of the call.
	 *
	 * @param HttpClient $httpClient Client the request is about to be sent with.
	 * @param CallType $callType Kind of the call — the only input the service class is derived from.
	 * @param string $feature Product label, travels as an ordinary header and never inside the token.
	 * @param int|null $userId Portal user behind the request; null for calls without one.
	 */
	public static function applyTo(
		HttpClient $httpClient,
		CallType $callType,
		string $feature,
		?int $userId = null,
	): ServiceClass
	{
		try
		{
			return self::attachTo($httpClient, $callType, $feature, $userId);
		}
		catch (\Throwable $exception)
		{
			// The context is information about the request, not a condition of it: whatever
			// fails on the way to the headers, the request itself must still go out. The
			// built-in class is returned so that the send point stays on the single
			// computation even when nothing could be read.
			self::log('error', 'context skipped: unexpected failure', ['type' => $exception::class]);

			return ServiceClass::builtInFor($callType);
		}
	}

	private static function attachTo(
		HttpClient $httpClient,
		CallType $callType,
		string $feature,
		?int $userId,
	): ServiceClass
	{
		// Order here is part of the contract, not layout: the label follows from the arguments
		// alone, so it is set before anything reaches for external state. Whatever the option,
		// the secret or the signing then runs into, it cannot take the label down with it.
		if (self::isWellFormedFeature($feature))
		{
			$httpClient->setHeader(self::HEADER_FEATURE, $feature);
		}
		else
		{
			self::log('warning', 'feature dropped: bad shape', ['feature' => $feature]);
		}

		$serviceClass = ServiceClass::forCallType($callType);

		$secret = self::getSecret();
		if ($secret === '')
		{
			self::log('notice', 'context skipped: no secret');

			return $serviceClass;
		}

		$token = self::issueToken($secret, $serviceClass, $userId);
		if ($token === null)
		{
			return $serviceClass;
		}

		if (strlen($token) > self::MAX_TOKEN_BYTES)
		{
			self::log('error', 'context skipped: token too long', ['length' => strlen($token)]);

			return $serviceClass;
		}

		$httpClient->setHeader(self::HEADER_SIGNED_CONTEXT, $token);
		self::log('debug', 'context sent', [
			'feature' => $feature,
			'class' => $serviceClass->claim,
			'callType' => $callType->value,
		]);

		return $serviceClass;
	}

	private static function issueToken(string $secret, ServiceClass $serviceClass, ?int $userId): ?string
	{
		$issuedAt = time();
		$claims = [
			'iss' => self::getMemberId(),
			'aud' => self::AUDIENCE,
			'sub' => $userId === null || $userId <= 0 ? '' : (string)$userId,
			'iat' => $issuedAt,
			'exp' => $issuedAt + self::TOKEN_TTL,
			'portalDomain' => self::getPortalDomain(),
			'priority' => $serviceClass->claim,
		];

		try
		{
			return JWT::encode($claims, $secret, 'HS256');
		}
		catch (\Throwable $exception)
		{
			// The secret must not reach the log, so only the class of failure is reported.
			self::log('error', 'context skipped: signing failed', ['type' => $exception::class]);

			return null;
		}
	}

	private static function getSecret(): string
	{
		$secret = Option::get('ai', self::OPTION_SECRET, '');

		return is_string($secret) ? $secret : '';
	}

	/**
	 * Portal identity in the outside world. Absent in a boxed install — the portal domain
	 * stays the only carrier of identity there.
	 */
	private static function getMemberId(): string
	{
		if (!Loader::includeModule('bitrix24') || !class_exists(\CBitrix24::class))
		{
			return '';
		}

		$memberId = \CBitrix24::getMemberId();

		return is_string($memberId) ? $memberId : '';
	}

	/**
	 * Bare portal host: no scheme, no port, no path. Empty when the source gives no host.
	 */
	private static function getPortalDomain(): string
	{
		$host = parse_url(UrlManager::getInstance()->getHostUrl(), PHP_URL_HOST);

		return is_string($host) ? $host : '';
	}

	/**
	 * Anchored with `D` on purpose: without it `$` also matches in front of a trailing line
	 * break, and a break inside a header value is how a second header gets forged.
	 */
	private static function isWellFormedFeature(string $feature): bool
	{
		return preg_match('/^[A-Za-z0-9._-]{1,' . self::FEATURE_MAX_LENGTH . '}$/D', $feature) === 1;
	}

	/**
	 * @internal Shared by the request-context classes so that every fact about the context
	 *           lands in one logger.
	 */
	public static function log(string $level, string $message, array $context = []): void
	{
		if ($context !== [])
		{
			// Rendered into the message rather than passed as context: the formatter only
			// substitutes {placeholders}, so context keys would otherwise never be seen.
			$pairs = [];
			foreach ($context as $key => $value)
			{
				// Values reach the log from the request itself — a feature label is a module id
				// the caller passed in. A line break inside one would forge a second log record,
				// so control characters are escaped rather than written through.
				$rendered = is_scalar($value) ? (string)$value : gettype($value);
				$pairs[] = $key . '=' . addcslashes($rendered, "\x00..\x1F\x7F");
			}

			$message .= ' [' . implode(', ', $pairs) . ']';
		}

		try
		{
			Diag\Logger::create(self::LOGGER_ID)?->log($level, $message);
		}
		catch (\Throwable)
		{
			// A log line is never worth the request it reports on.
		}
	}
}
