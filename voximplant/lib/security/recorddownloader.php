<?php

namespace Bitrix\Voximplant\Security;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\IpAddress;
use Bitrix\Main\Web\Uri;
use Psr\Http\Message\RequestInterface;

/**
 * Safely downloads an untrusted record URL into a stream.
 *
 * Every hop (initial request and each redirect target) is validated with
 * {@see PublicUrlValidator} before the network request, and the request is sent
 * straight to the validated address, so there is no window for a second,
 * unchecked DNS resolution between validation and connection (closes the
 * DNS-rebinding/TOCTOU vector). On a modern core the connection is pinned via
 * the client's effectiveIp mechanism: the URL, the Host header and the TLS
 * server name keep the original host while the TCP connection goes to the
 * pinned address. On an older core the URL host is replaced with the pinned
 * IP while the original Host header and TLS server name are preserved.
 * Redirects are followed manually so that the target of each Location can be
 * re-validated. The outgoing proxy is intentionally bypassed: the client is
 * created directly, not via HttpClientFactory, to keep the address checks
 * meaningful.
 */
class RecordDownloader
{
	public const ERROR_CODE_IPV6_NOT_SUPPORTED = 'IPV6_NOT_SUPPORTED';
	public const ERROR_CODE_EMPTY_PINNED_IP = 'EMPTY_PINNED_IP';

	/**
	 * Error codes that are deterministic for a given URL: retrying the download
	 * is guaranteed to reach the same outcome. PRIVATE_IP is intentionally left
	 * out: the host name or a redirect target may resolve to a public address on
	 * a later attempt, and every retry re-runs the full validation, so it stays
	 * fail-closed while remaining retryable.
	 */
	public const TERMINAL_ERROR_CODES = [
		PublicUrlValidator::ERROR_CODE_INVALID_URL,
		self::ERROR_CODE_IPV6_NOT_SUPPORTED,
		self::ERROR_CODE_EMPTY_PINNED_IP,
	];

	private const MAX_REDIRECTS = 5;
	private const CONNECT_TIMEOUT = 30;
	private const STREAM_TIMEOUT = 60;

	/**
	 * Safety bound against an untrusted RECORD_URL streaming an unbounded body
	 * into a temp file (disk-fill DoS). Far above any real call recording;
	 * exceeding it aborts the download with a network error instead of writing a
	 * truncated file.
	 */
	private const MAX_BODY_BYTES = 536870912; // 512 MiB

	/**
	 * Validates and downloads $url into $stream following redirects manually.
	 * On success the response body of the final hop is written to the stream.
	 *
	 * @param resource $stream Open writable stream for the response body.
	 * @return Result Data: ['status' => int, 'httpClient' => HttpClient].
	 */
	public static function downloadToStream(string $url, $stream): Result
	{
		$result = new Result();

		$httpClient = static::createHttpClient();
		$result->setData([
			'status' => 0,
			'httpClient' => $httpClient,
		]);

		// A proxy resolves the host name on its own, which would defeat the pin;
		// with a proxy the host substitution keeps the request going to the
		// pinned address.
		$pinViaEffectiveIp = method_exists($httpClient, 'setPinnedIp') && !$httpClient->hasProxy();

		$currentUrl = $url;
		for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++)
		{
			$validation = static::validateUrl($currentUrl);
			if (!$validation->isSuccess())
			{
				return $result->addErrors($validation->getErrors());
			}

			$pinnedIp = (string)($validation->getData()['ip'] ?? '');

			// Without a pinned address neither path can connect to the validated
			// IP: the core would resolve the host again at connect time, which
			// reopens the DNS-rebinding window. Fail closed instead.
			if ($pinnedIp === '')
			{
				return $result->addError(new Error(
					'The record URL host did not resolve to a usable address.',
					self::ERROR_CODE_EMPTY_PINNED_IP
				));
			}

			if ($pinViaEffectiveIp)
			{
				// The core rejects an IPv6 literal as a URI host (CBXPunycode fails),
				// so a bracketed pinned URL cannot connect. This is deterministic, so
				// classify it as terminal here instead of letting query() fail as a
				// retryable error. AAAA-only domains are unaffected: their URL host is
				// a name, not a literal.
				$urlHost = trim((string)parse_url($currentUrl, PHP_URL_HOST), '[]');
				if (filter_var($urlHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false)
				{
					return $result->addError(new Error(
						'The record URL uses an IPv6 literal host, which is not supported by this environment.',
						self::ERROR_CODE_IPV6_NOT_SUPPORTED
					));
				}

				$httpClient->setPinnedIp(self::formatHostForTransport($pinnedIp));
				$connectUrl = $currentUrl;
			}
			else
			{
				// The core rejects IPv6 literals as a URI host (punycode
				// conversion fails), so the host substitution cannot connect
				// to an IPv6 pin.
				if (str_contains($pinnedIp, ':'))
				{
					return $result->addError(new Error(
						'The record URL host resolves only to IPv6 addresses; downloading from IPv6-only hosts is not supported by this environment.',
						self::ERROR_CODE_IPV6_NOT_SUPPORTED
					));
				}

				$originalUri = new Uri($currentUrl);
				$connectUrl = (new Uri($currentUrl))->setHost($pinnedIp)->getUri();
				$httpClient->setHeader('Host', self::buildHostHeader($originalUri), true);
				$httpClient->setContextOptions(['ssl' => ['peer_name' => $originalUri->getHost()]]);
			}

			if ($httpClient->query(HttpClient::HTTP_GET, $connectUrl) === false)
			{
				foreach ($httpClient->getError() as $code => $message)
				{
					$result->addError(new Error($code . ': ' . $message, PublicUrlValidator::ERROR_CODE_SERVER_NOT_AVAILABLE));
				}

				return $result;
			}

			$status = $httpClient->getStatus();
			$result->setData([
				'status' => $status,
				'httpClient' => $httpClient,
			]);

			$location = (string)$httpClient->getHeaders()->get('Location');
			if ($status >= 300 && $status < 400 && $location !== '')
			{
				$currentUrl = (string)(new Uri($location))->resolveRelativeUri(new Uri($currentUrl));
				continue;
			}

			if ($status !== 200)
			{
				return $result;
			}

			$httpClient->setOutputStream($stream);
			$httpClient->getResult();

			return $result;
		}

		return $result->addError(
			new Error('Maximum number of redirects has been reached.', 'REDIRECT')
		);
	}

	/**
	 * Seam for tests: production validates via {@see PublicUrlValidator}.
	 * Overridable so a test can drive the download loop against a local
	 * server without disabling the real validator (which PublicUrlValidatorTest
	 * already covers in full).
	 */
	protected static function validateUrl(string $url): Result
	{
		return PublicUrlValidator::validate($url);
	}

	/**
	 * Creates the client. When the core supports pinning the connection to a
	 * pre-resolved address (the effectiveIp mechanism honored by the transport
	 * handlers), returns a subclass that pins each request to the address set
	 * via setPinnedIp() while the URL keeps the original host.
	 *
	 * The plain-client fallback (host-substitution path) is a safety net for a
	 * future core change of the checkRequest()/effectiveIp signature or
	 * visibility, which canPinEffectiveIp() would detect. On a genuinely old
	 * pre-PSR core the download path is inoperable anyway (query() reads the
	 * body, Uri::resolveRelativeUri() is missing); such cores are unsupported.
	 */
	protected static function createHttpClient(): HttpClient
	{
		$options = [
			'disableSslVerification' => true,
			'redirect' => false,
			'socketTimeout' => self::CONNECT_TIMEOUT,
			'streamTimeout' => self::STREAM_TIMEOUT,
			// Cap the body an untrusted URL can stream into the temp file.
			'bodyLengthMax' => self::MAX_BODY_BYTES,
			// An untrusted RECORD_URL must reach exactly the address we validated.
			// Disabling the OnHttpClientBuildRequest event stops any handler (e.g. an
			// outbound signing proxy) from injecting a proxy after the pin is set and
			// rerouting the request through an unchecked DNS resolution; the proxy is
			// kept empty explicitly for the same reason.
			'proxyHost' => '',
			'sendEvents' => false,
			// The address is already validated by PublicUrlValidator and pinned via
			// effectiveIp, so the core's IPv4-only re-resolve and private-IP check are
			// disabled explicitly here instead of relying on the ambient global config;
			// otherwise a portal with http_client_options[privateIp] = false breaks
			// legitimate AAAA-only domains.
			'privateIp' => true,
		];

		if (!self::canPinEffectiveIp())
		{
			return new HttpClient($options);
		}

		return new class($options) extends HttpClient
		{
			private string $pinnedIp = '';

			public function setPinnedIp(string $ip): void
			{
				$this->pinnedIp = $ip;
			}

			public function hasProxy(): bool
			{
				return $this->proxyHost != '';
			}

			protected function checkRequest(RequestInterface $request): bool
			{
				if (!parent::checkRequest($request))
				{
					return false;
				}

				if ($this->pinnedIp !== '')
				{
					$this->effectiveIp = new IpAddress($this->pinnedIp);
				}

				return true;
			}
		};
	}

	/**
	 * Tells whether the core client lets a subclass pin the connection address:
	 * checkRequest() is overridable and effectiveIp is visible to descendants.
	 */
	private static function canPinEffectiveIp(): bool
	{
		if (!method_exists(HttpClient::class, 'sendRequest'))
		{
			return false;
		}

		try
		{
			$checkRequest = new \ReflectionMethod(HttpClient::class, 'checkRequest');
			$effectiveIp = new \ReflectionProperty(HttpClient::class, 'effectiveIp');
		}
		catch (\ReflectionException)
		{
			return false;
		}

		if (!$checkRequest->isProtected() || !$effectiveIp->isProtected())
		{
			return false;
		}

		return self::hasExpectedCheckRequestSignature($checkRequest);
	}

	/**
	 * Guards against defining the pinning subclass on a core whose
	 * checkRequest() has an incompatible signature. An incompatible override
	 * fails at class-linking time with a fatal error that no try/catch around
	 * the anonymous class can intercept, so the mismatch must be detected here.
	 * Requires exactly one non-nullable RequestInterface parameter and a
	 * non-nullable bool return type, matching the override declared in
	 * createHttpClient(); union types and any other shape degrade to fallback.
	 */
	private static function hasExpectedCheckRequestSignature(\ReflectionMethod $method): bool
	{
		$params = $method->getParameters();
		if (count($params) !== 1)
		{
			return false;
		}

		$paramType = $params[0]->getType();
		if (
			!$paramType instanceof \ReflectionNamedType
			|| $paramType->allowsNull()
			|| $paramType->getName() !== RequestInterface::class
		)
		{
			return false;
		}

		$returnType = $method->getReturnType();

		return $returnType instanceof \ReflectionNamedType
			&& !$returnType->allowsNull()
			&& $returnType->getName() === 'bool';
	}

	/**
	 * Wraps an IPv6 literal in brackets, as required both in a URI authority
	 * and in a tcp:// socket address; IPv4 addresses are returned unchanged.
	 */
	private static function formatHostForTransport(string $ip): string
	{
		return str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
	}

	/**
	 * Builds the Host header value from the original URL: the original host
	 * plus the port only when it is explicitly non-default.
	 */
	private static function buildHostHeader(Uri $uri): string
	{
		$host = $uri->getHost();
		$port = $uri->getPort();
		$scheme = $uri->getScheme();

		$isDefaultPort =
			($scheme === 'http' && $port === 80)
			|| ($scheme === 'https' && $port === 443)
		;

		return ($port !== null && !$isDefaultPort) ? $host . ':' . $port : $host;
	}
}
