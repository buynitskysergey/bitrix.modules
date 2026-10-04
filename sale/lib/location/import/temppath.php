<?php

namespace Bitrix\Sale\Location\Import;

use Bitrix\Main\IO\Path;

final class TempPath
{
	private const DIRECTORY_SEPARATOR = '/';

	public static function resolve(?string $legacyPath, string $defaultContext = 'sale'): ?string
	{
		if ($legacyPath === null)
		{
			return self::normalizeDirectory(\CTempFile::GetDirectoryName(12, $defaultContext));
		}

		if (!self::isValidAbsolutePath($legacyPath))
		{
			return null;
		}

		try
		{
			$lexicalRoot = Path::normalize(\CTempFile::GetAbsoluteRoot());
			$lexicalCandidate = Path::normalize($legacyPath);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (
			!is_string($lexicalRoot)
			|| !is_string($lexicalCandidate)
			|| !Path::isAbsolute($lexicalRoot)
			|| !Path::isAbsolute($lexicalCandidate)
			|| !self::isWithin($lexicalCandidate, $lexicalRoot)
		)
		{
			return null;
		}

		if (!self::createDirectory($lexicalRoot))
		{
			return null;
		}

		$canonicalRoot = self::canonicalize($lexicalRoot);
		if ($canonicalRoot === null)
		{
			return null;
		}

		$existingAncestor = self::findExistingAncestor($lexicalCandidate);
		$canonicalAncestor = $existingAncestor === null ? null : self::canonicalize($existingAncestor);
		if ($canonicalAncestor === null || !self::isWithin($canonicalAncestor, $canonicalRoot))
		{
			return null;
		}

		if (!self::createDirectory($lexicalCandidate))
		{
			return null;
		}

		$canonicalCandidate = self::canonicalize($lexicalCandidate);
		if ($canonicalCandidate === null || !self::isWithin($canonicalCandidate, $canonicalRoot))
		{
			return null;
		}

		return self::withTrailingSeparator($canonicalCandidate);
	}

	private static function isValidAbsolutePath(string $path): bool
	{
		return $path !== ''
			&& !str_contains($path, "\0")
			&& preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) !== 1
			&& Path::isAbsolute(str_replace('\\', '/', $path))
		;
	}

	private static function createDirectory(string $path): bool
	{
		return \CheckDirPath(self::withTrailingSeparator($path)) && is_dir($path);
	}

	private static function canonicalize(string $path): ?string
	{
		$canonicalPath = realpath($path);
		if ($canonicalPath === false || !is_dir($canonicalPath))
		{
			return null;
		}

		return self::normalizeDirectory($canonicalPath);
	}

	private static function normalizeDirectory(string $path): ?string
	{
		try
		{
			$normalizedPath = Path::normalize($path);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_string($normalizedPath) ? self::withTrailingSeparator($normalizedPath) : null;
	}

	private static function findExistingAncestor(string $path): ?string
	{
		$currentPath = $path;

		while (!file_exists($currentPath) && !is_link($currentPath))
		{
			$parentPath = dirname($currentPath);
			if ($parentPath === $currentPath)
			{
				return null;
			}

			$currentPath = $parentPath;
		}

		return $currentPath;
	}

	private static function isWithin(string $candidate, string $root): bool
	{
		$candidate = self::withoutTrailingSeparator($candidate);
		$root = self::withoutTrailingSeparator($root);

		if (self::isWindows())
		{
			$candidate = mb_strtolower($candidate);
			$root = mb_strtolower($root);
		}

		if ($candidate === $root)
		{
			return true;
		}

		if ($root === '')
		{
			return str_starts_with($candidate, self::DIRECTORY_SEPARATOR);
		}

		return str_starts_with($candidate, $root . self::DIRECTORY_SEPARATOR);
	}

	private static function withTrailingSeparator(string $path): string
	{
		return self::withoutTrailingSeparator($path) . self::DIRECTORY_SEPARATOR;
	}

	private static function withoutTrailingSeparator(string $path): string
	{
		return rtrim(str_replace('\\', self::DIRECTORY_SEPARATOR, $path), self::DIRECTORY_SEPARATOR);
	}

	private static function isWindows(): bool
	{
		return strncasecmp(PHP_OS, 'WIN', 3) === 0;
	}
}
