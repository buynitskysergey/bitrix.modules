<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\File;

use Bitrix\Main\Web\Http;
use Bitrix\Main\Web\Http\Curl\Handler as CurlHandler;
use Bitrix\Main\Web\HttpClient;
use Psr\Http\Message\RequestInterface;

final class RemoteImageHttpClient extends HttpClient
{
	private const MILLISECONDS_IN_SECOND = 1000;

	private ?float $deadline = null;

	public function __construct(private readonly int $totalTimeoutSeconds)
	{
		if ($totalTimeoutSeconds <= 0)
		{
			throw new \InvalidArgumentException('The total timeout must be positive.');
		}

		parent::__construct(['useCurl' => true]);
	}

	public function download(
		$url,
		$filePath,
		string $method = Http\Method::GET,
		$entityBody = null,
	)
	{
		$this->deadline = microtime(true) + $this->totalTimeoutSeconds;

		try
		{
			return parent::download($url, $filePath, $method, $entityBody);
		}
		finally
		{
			$this->deadline = null;
		}
	}

	protected function createHandler(RequestInterface $request, bool $async = false)
	{
		$handler = parent::createHandler($request, $async);
		if (!$handler instanceof CurlHandler || $this->deadline === null)
		{
			throw new \RuntimeException('The remote image download requires cURL.');
		}

		$remainingMilliseconds = (int)floor(
			($this->deadline - microtime(true)) * self::MILLISECONDS_IN_SECOND,
		);
		if ($remainingMilliseconds <= 0)
		{
			throw new \RuntimeException('The remote image download deadline has expired.');
		}

		if (!curl_setopt($handler->getHandle(), CURLOPT_TIMEOUT_MS, $remainingMilliseconds))
		{
			throw new \RuntimeException('The remote image download deadline could not be configured.');
		}

		return $handler;
	}
}
