<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\Web\Uri;

class ChecklistTitleUrlFormatter
{
	private const RAW_HTTP_URL_PATTERN
		= '/(^|[\s.,;:!?\#\-\*\|\[\]\(\)\{\}])(https?:\/\/[^\s"{}<>\[\]]+)/iu';
	private const URL_FORBIDDEN_CHARS_PATTERN = '/[\s"\'{}<>\[\]]/u';
	private const IM_AUTOLINK_PROTECTION_TAG = 'nomodify';

	public function containsRawUrl(string $text): bool
	{
		return preg_match(self::RAW_HTTP_URL_PATTERN, $text) === 1;
	}

	public function protectRawUrls(string $text): string
	{
		return preg_replace_callback(
			self::RAW_HTTP_URL_PATTERN,
			fn (array $matches): string => $matches[1] . $this->disableImAutolink($matches[2]),
			$text,
		);
	}

	public function sanitize(string $url): string
	{
		$decodedUrl = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($decodedUrl === '' || preg_match(self::URL_FORBIDDEN_CHARS_PATTERN, $decodedUrl) === 1)
		{
			return '';
		}

		$uri = new Uri($decodedUrl);
		$scheme = $uri->getScheme();
		$host = $uri->getHost();
		if (
			($scheme === 'http' || $scheme === 'https')
			&& ($host === 'localhost' || str_contains($host, '.'))
		)
		{
			return $decodedUrl;
		}

		return '';
	}

	private function disableImAutolink(string $url): string
	{
		return sprintf(
			'<%1$s>%2$s</%1$s>',
			self::IM_AUTOLINK_PROTECTION_TAG,
			$url,
		);
	}
}
