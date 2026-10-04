<?php

namespace Bitrix\Sale\Location\Import;

use Bitrix\Main\Web\HttpClient;

final class Downloader
{
	private HttpClient $httpClient;

	public function __construct(HttpClient $httpClient)
	{
		$this->httpClient = $httpClient;
	}

	public function download(
		string $server,
		int|string|null $port,
		string $path,
		string $fileName,
		string $method
	): ?string
	{
		$this->httpClient->setRedirect(true);
		$this->httpClient->setPrivateIp(false);

		$url = $server . ($port === null ? '' : ':' . $port) . $path . $fileName;
		if (!$this->isValidUrl($url))
		{
			return null;
		}

		$data =
			strtoupper($method) === HttpClient::HTTP_POST
				? $this->httpClient->post($url)
				: $this->httpClient->get($url)
		;

		return is_string($data) ? $data : null;
	}

	private function isValidUrl(string $url): bool
	{
		if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1)
		{
			return false;
		}

		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host']))
		{
			return false;
		}

		return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
	}
}
