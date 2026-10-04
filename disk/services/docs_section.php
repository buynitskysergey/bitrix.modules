<?php

use Bitrix\Main\Application;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\InvalidPathException;
use Bitrix\Main\IO\Path;
use Bitrix\Main\SiteTable;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Terminal entry point for the docs section under the new routing.
 *
 * Reached only when the `/docs/{any}` terminal route matches a 404 subpath (no physical file).
 * Such routing entry points are executed before the prolog, so no module can be loaded here
 * (there is neither LANGUAGE_ID nor $APPLICATION yet) and only the Bitrix\Main autoload is
 * available. The resolve below is therefore filesystem-only: it does not touch the database
 * or any storage domain logic.
 *
 * The request is resolved to the nearest physical index.php of a docs section ancestor
 * (walk-up, strictly confined within the docs root); on any ambiguity it falls back to the docs
 * section page. REQUEST_URI is left untouched: the bitrix:disk.common component parses the
 * subpath itself.
 */

$request = Application::getInstance()->getContext()->getRequest();

$siteDir = '/';
$site = SiteTable::getByDomain($request->getHttpHost(), $request->getRequestedPageDirectory());
if ($site)
{
	$siteDir = $site['DIR'] ?? '/';
}
$docsBaseDir = rtrim($siteDir, '/') . '/docs';

// the file is included in the global scope right before the resolved portal page, hence the
// closure: neither helper functions nor intermediate variables may leak into that page
$logicalPath = (static function (string $documentRoot, string $docsBaseDir, string $requestUri): string
{
	$documentRoot = rtrim($documentRoot, '/');
	$docsBaseDir = rtrim($docsBaseDir, '/');
	$fallback = $docsBaseDir . '/index.php';

	$normalizeRequestPath = static function (string $requestUri): ?string
	{
		$rawPath = explode('?', $requestUri, 2)[0];
		$rawPath = explode('#', $rawPath, 2)[0];

		$segments = array_map('rawurldecode', explode('/', trim($rawPath, '/')));
		foreach ($segments as $segment)
		{
			// an encoded separator inside a single segment (e.g. %2f/%5c) must not be reinterpreted
			// as a path separator; such a request stays ambiguous and falls back to the docs section.
			if (str_contains($segment, '/') || str_contains($segment, '\\'))
			{
				return null;
			}
		}

		try
		{
			return Path::normalize('/' . implode('/', $segments));
		}
		catch (InvalidPathException)
		{
			return null;
		}
	};

	$path = $normalizeRequestPath($requestUri);
	if ($path === null)
	{
		return $fallback;
	}

	if ($path !== $docsBaseDir && !str_starts_with($path, $docsBaseDir . '/'))
	{
		return $fallback;
	}

	try
	{
		$rootPhysical = Path::normalize($documentRoot . $docsBaseDir);
	}
	catch (InvalidPathException)
	{
		return $fallback;
	}

	if ($rootPhysical === null)
	{
		return $fallback;
	}

	$dir = rtrim($path, '/');
	while ($dir !== '')
	{
		$candidate = $dir . '/index.php';

		try
		{
			$physical = Path::normalize($documentRoot . $candidate);
		}
		catch (InvalidPathException)
		{
			break;
		}

		if ($physical === null || !str_starts_with($physical, $rootPhysical . '/'))
		{
			break;
		}

		if (File::isFileExists($physical))
		{
			return $candidate;
		}

		if ($dir === $docsBaseDir)
		{
			break;
		}

		$separatorPosition = strrpos($dir, '/');
		$dir = $separatorPosition === false ? '' : substr($dir, 0, $separatorPosition);
	}

	return $fallback;
})((string)$_SERVER['DOCUMENT_ROOT'], $docsBaseDir, $request->getRequestUri());

include_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/classes/general/virtual_io.php';
$io = \CBXVirtualIo::GetInstance();

$_SERVER['REAL_FILE_PATH'] = $logicalPath;

include_once $io->GetPhysicalName($_SERVER['DOCUMENT_ROOT'] . $logicalPath);

die();
