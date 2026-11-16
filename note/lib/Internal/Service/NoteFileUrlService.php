<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service;

use Bitrix\Main\Engine\UrlManager;

class NoteFileUrlService
{
	public static function createShowUrl(int $fileId, bool $preview = false): string
	{
		if ($fileId <= 0)
		{
			return '';
		}

		$uri = UrlManager::getInstance()->create(
			'note.infrastructure.FileController.show',
			self::buildShowParameters($fileId, $preview),
			false,
		);

		// Always return relative url to avoid mixed-content in https contexts.
		return $uri->getPathQuery();
	}

	/**
	 * The address a visitor of a public link reads a file from.
	 *
	 * No token in the address, and deliberately so: this url is substituted into `<img src>` and into
	 * the download link, from where it travels into the browser history, the referer of the next
	 * request and the log of the web server. The link the visitor stands in is recognised on the
	 * server side instead, by the marks of their session.
	 */
	public static function createPublicShowUrl(int $fileId, bool $preview = false): string
	{
		return self::createPublicUrl('note.external.FileController.show', $fileId, $preview);
	}

	/**
	 * The same file, as an attachment rather than inline: the public circuit has two actions where the
	 * ordinary one has one, because a visitor has no place to POST a resolveDownload from - the
	 * download is a plain link in the page.
	 */
	public static function createPublicDownloadUrl(int $fileId): string
	{
		return self::createPublicUrl('note.external.FileController.download', $fileId);
	}

	/**
	 * The name of the action goes into the address as the engine resolves it: with the class name
	 * whole, `Controller` suffix included (`Main\Engine\Resolver::buildControllerClassName()` takes
	 * the last segment as the class name as it is). The short form answered "controller not found",
	 * and here that answer would arrive as a broken image in the page rather than as an error the
	 * client could report.
	 */
	private static function createPublicUrl(string $action, int $fileId, bool $preview = false): string
	{
		if ($fileId <= 0)
		{
			return '';
		}

		return UrlManager::getInstance()
			->create($action, self::buildShowParameters($fileId, $preview), false)
			->getPathQuery()
		;
	}

	/**
	 * Without the flag the address stays byte for byte what it was: it is the key under which the
	 * browser has already cached the file.
	 *
	 * @return array<string, int>
	 */
	private static function buildShowParameters(int $fileId, bool $preview): array
	{
		$parameters = ['fileId' => $fileId];
		if ($preview)
		{
			$parameters['preview'] = 1;
		}

		return $parameters;
	}
}
