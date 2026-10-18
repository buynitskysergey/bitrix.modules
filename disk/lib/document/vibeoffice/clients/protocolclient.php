<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Clients;

use Bitrix\Disk\User;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Default ProtocolClient implementation over Bitrix\Main\Web\HttpClient.
 *
 * Builds the url/path/body, percent-encodes docKey/userId, signs each request via
 * Signer (4 X-VO-* headers) and maps platform status codes into a Result.
 *
 * The api-secret used by the Signer never leaves the backend (ADR invariant): it
 * is only used to compute the signature here. All calls are cross-service and run
 * outside any DB transaction.
 */
final class ProtocolClient implements ProtocolClientInterface
{
	private string $baseUrl;
	private Signer $signer;
	private AvatarSourceResolver $avatarSourceResolver;
	/** @var (\Closure(): HttpClient)|null */
	private ?\Closure $httpClientFactory;
	/** @var array<int, string|null> avatar url per user, looked up once for the lifetime of the client */
	private array $avatarUrlByUser = [];

	/**
	 * @param (\Closure(): HttpClient)|null $httpClientFactory test seam; without it
	 *        the client is built by {@see buildHttpClient()} with the production timeouts.
	 */
	public function __construct(
		string $baseUrl,
		Signer $signer,
		?AvatarSourceResolver $avatarSourceResolver = null,
		?\Closure $httpClientFactory = null
	)
	{
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->signer = $signer;
		$this->avatarSourceResolver = $avatarSourceResolver ?? AvatarSourceResolver::createForCurrentPortal();
		$this->httpClientFactory = $httpClientFactory;
	}

	public function open(string $docKey, array $body): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/open';

		return $this->send(HttpClient::HTTP_POST, $path, $this->withUserAvatar($body));
	}

	private function withUserAvatar(array $body): array
	{
		$userId = $body['user']['id'] ?? null;
		if (
			(!is_int($userId) && !is_string($userId))
			|| preg_match('/^[0-9]+$/D', (string)$userId) !== 1
			|| (int)$userId <= 0
		)
		{
			return $body;
		}

		$avatarUrl = $this->lookUpAvatarUrl((int)$userId);
		if ($avatarUrl === null)
		{
			return $body;
		}

		return $this->withAvatarUrl($body, $avatarUrl);
	}

	/**
	 * The avatar of a user, looked up once per user.
	 *
	 * The retry cycle of the open sends the body of the first attempt again, and a lookup here would
	 * be paid for by every attempt of it; the answer cannot change meanwhile, because a client serves
	 * one request. A user without an avatar is remembered as readily as one with it, so a second
	 * attempt asks about neither.
	 */
	private function lookUpAvatarUrl(int $userId): ?string
	{
		if (array_key_exists($userId, $this->avatarUrlByUser))
		{
			return $this->avatarUrlByUser[$userId];
		}

		$avatarUrl = null;
		try
		{
			$loadedUrl = User::loadById($userId)?->getOwnAvatarSrc();
			if ($loadedUrl !== null && $loadedUrl !== '')
			{
				$avatarUrl = $loadedUrl;
			}
		}
		catch (\Throwable)
		{
		}

		$this->avatarUrlByUser[$userId] = $avatarUrl;

		return $avatarUrl;
	}

	private function withAvatarUrl(array $body, string $avatarUrl): array
	{
		$avatarSource = $this->avatarSourceResolver->resolve($avatarUrl);
		if ($avatarSource === null)
		{
			return $body;
		}

		$body['user']['image'] = $avatarSource;

		return $body;
	}

	public function upsertGrants(string $docKey, array $grants): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/grants';

		return $this->send(HttpClient::HTTP_POST, $path, $grants);
	}

	public function revokeGrant(string $docKey, string $userId): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/grants/' . self::encodeSegment($userId);

		return $this->send(HttpClient::HTTP_DELETE, $path, null);
	}

	public function forcesave(string $docKey, ?array $userdata): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/forcesave';

		return $this->send(HttpClient::HTTP_POST, $path, $userdata);
	}

	public function updateMeta(string $docKey, string $title): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/meta';

		return $this->send(HttpClient::HTTP_POST, $path, ['title' => $title]);
	}

	public function convert(array $body): Result
	{
		return $this->send(HttpClient::HTTP_POST, '/v1/convert', $body);
	}

	public function signVersionUrls(string $docKey, array $body): Result
	{
		$path = '/v1/documents/' . self::encodeSegment($docKey) . '/versions/sign-urls';

		return $this->send(HttpClient::HTTP_POST, $path, $body);
	}

	/**
	 * Percent-encodes a single path segment so that "/" becomes "%2F"
	 * (RFC 3986; rawurlencode does not turn spaces into "+").
	 */
	private static function encodeSegment(string $segment): string
	{
		return rawurlencode($segment);
	}

	/**
	 * Encodes the body, signs the request and sends it through HttpClient.
	 *
	 * @param string $method HTTP method exactly as it goes on the wire
	 * @param string $escapedPath percent-encoded path exactly as it goes on the wire
	 * @param array|null $body payload, or null for a body-less request (e.g. DELETE)
	 */
	private function send(string $method, string $escapedPath, ?array $body): Result
	{
		$result = new Result();

		if ($body === null)
		{
			$rawBody = '';
		}
		else
		{
			try
			{
				$rawBody = Json::encode($body);
			}
			catch (ArgumentException $e)
			{
				return $result->addError(new Error($e->getMessage()));
			}
		}

		$ts = time();
		$nonce = Signer::generateNonce();

		$headers = $this->signer->buildHeaders($method, $escapedPath, '', $rawBody, $ts, $nonce);

		$http = $this->httpClientFactory === null ? $this->buildHttpClient() : ($this->httpClientFactory)();
		foreach ($headers as $name => $value)
		{
			$http->setHeader($name, $value);
		}

		$url = $this->baseUrl . $escapedPath;

		$entityBody = $rawBody === '' ? null : $rawBody;
		if ($http->query($method, $url, $entityBody) === false)
		{
			return $result->addError(new Error('Vibeoffice server is not available.'));
		}

		return $this->handleResponse($result, $http);
	}

	private function handleResponse(Result $result, HttpClient $http): Result
	{
		$status = (int)$http->getStatus();

		if ($status >= 200 && $status < 300)
		{
			$result->setData($this->decodeBody((string)$http->getResult()));

			return $result;
		}

		$retryAfter = self::parseRetryAfter($http->getHeaders()->get('Retry-After'));

		return $result->addError($this->mapError($status, (string)$http->getResult(), $retryAfter));
	}

	/**
	 * Reads Retry-After as delay-seconds only: an HTTP-date, any non-numeric value
	 * and a non-positive delay all give null. An upper bound belongs to the retry
	 * policy, not to the transport.
	 */
	private static function parseRetryAfter(mixed $headerValue): ?int
	{
		if (!is_string($headerValue))
		{
			return null;
		}

		$delaySeconds = trim($headerValue);
		if (preg_match('/^[0-9]+$/D', $delaySeconds) !== 1)
		{
			return null;
		}

		$seconds = (int)$delaySeconds;

		return $seconds > 0 ? $seconds : null;
	}

	/**
	 * Decodes a successful JSON response body into an array, tolerant of an empty
	 * or non-JSON body (returns an empty array).
	 */
	private function decodeBody(string $rawResponse): array
	{
		if ($rawResponse === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($rawResponse);
		}
		catch (ArgumentException $e)
		{
			return [];
		}

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Maps a platform HTTP status (and optional JSON error code in the body)
	 * into a human-readable Bitrix\Main\Error.
	 *
	 * The status, the platform code and the retry delay also go into the error
	 * custom data (DTO-OPENERR) - that structure, and not the message, is what the
	 * controller branches on. The `Code: ...` suffix stays in the message for the logs
	 * and for the callers that still parse it.
	 */
	private function mapError(int $status, string $rawResponse, ?int $retryAfter): Error
	{
		$platformCode = $this->extractPlatformCode($rawResponse);

		$message = match ($status)
		{
			401 => 'Vibeoffice authentication failed (invalid key, replay, bad signature or timestamp skew).',
			403 => 'Vibeoffice access denied: no grant.',
			409 => 'Vibeoffice request conflict (operation in progress or document is full).',
			413 => 'Vibeoffice payload is too large.',
			422 => 'Vibeoffice rejected the request as unprocessable.',
			502 => 'Vibeoffice bad gateway.',
			503 => 'Vibeoffice service is unavailable.',
			default => 'Vibeoffice server returned an error. Status ' . $status . '.',
		};

		if ($platformCode !== null)
		{
			$message .= ' Code: ' . $platformCode . '.';
		}

		return new Error($message, $status, [
			'httpStatus' => $status,
			'platformCode' => $platformCode,
			'retryAfter' => $retryAfter,
		]);
	}

	/**
	 * Extracts the platform error code from the JSON response body, if present.
	 * The platform wraps it into an `{"error": {"code": ...}}` envelope; the flat
	 * shape stays supported. Tolerant of non-JSON or unexpected shapes.
	 */
	private function extractPlatformCode(string $rawResponse): ?string
	{
		if ($rawResponse === '')
		{
			return null;
		}

		try
		{
			$decoded = Json::decode($rawResponse);
		}
		catch (ArgumentException $e)
		{
			return null;
		}

		if (!is_array($decoded))
		{
			return null;
		}

		if (is_array($decoded['error'] ?? null))
		{
			return self::readFirstScalar($decoded['error'], ['code', 'error_code']);
		}

		return self::readFirstScalar($decoded, ['code', 'error', 'error_code']);
	}

	/**
	 * @param string[] $fields
	 */
	private static function readFirstScalar(array $source, array $fields): ?string
	{
		foreach ($fields as $field)
		{
			if (isset($source[$field]) && is_scalar($source[$field]))
			{
				return (string)$source[$field];
			}
		}

		return null;
	}

	private function buildHttpClient(): HttpClient
	{
		$http = new HttpClient([
			'socketTimeout' => 5,
			'streamTimeout' => 10,
			'version' => HttpClient::HTTP_1_1,
		]);

		$http->setHeader('Content-Type', 'application/json');
		$http->setRedirect(false);

		return $http;
	}
}
