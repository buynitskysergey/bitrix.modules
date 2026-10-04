<?php

namespace Bitrix\Disk\Integration\Fileman;

final class CommonStoragePathNormalizer
{
	/**
	 * Converts a fileman wizard path to a Disk common storage mount point.
	 *
	 * @param string $rawPath
	 * @return string
	 */
	public static function normalizeCommonStorageMountPoint(
		string $rawPath,
		string $siteDir = '/',
		bool $allowLegacyDuplicateCollapse = false
	): string
	{
		$path = trim($rawPath);
		$path = preg_replace('/[?#].*$/', '', $path) ?? '';
		$path = str_replace('\\', '/', $path);
		$path = preg_replace('#/+#', '/', $path) ?? '';
		$path = preg_replace('#(?:^|/)index\.php/*$#', '', $path) ?? '';

		$path = trim($path, '/');
		if ($path === '')
		{
			return '/';
		}

		$segments = explode('/', $path);
		$siteSegments = self::getSiteSegments($siteDir);
		$docsIndex = 0;
		if (
			$siteSegments !== []
			&& array_slice($segments, 0, count($siteSegments)) === $siteSegments
		)
		{
			$docsIndex = count($siteSegments);
		}

		if (
			$allowLegacyDuplicateCollapse
			&& ($segments[$docsIndex] ?? null) === 'docs'
			&& count($segments) - $docsIndex >= 3
			&& $segments[count($segments) - 1] === $segments[count($segments) - 2]
		)
		{
			array_pop($segments);
		}

		return '/' . implode('/', $segments) . '/';
	}

	private static function getSiteSegments(string $siteDir): array
	{
		$siteDir = trim(preg_replace('#/+#', '/', str_replace('\\', '/', $siteDir)) ?? '', '/');
		if ($siteDir === '')
		{
			return [];
		}

		return explode('/', $siteDir);
	}
}
