<?php

namespace Bitrix\Disk\Integration\Fileman;

final class CommonStorageCreationContext
{
	private static $storageId;
	private static $mountPoint;

	public static function remember(int $storageId, string $mountPoint): void
	{
		self::$storageId = $storageId;
		self::$mountPoint = $mountPoint;
	}

	public static function consume(): ?array
	{
		if (self::$storageId === null || self::$mountPoint === null)
		{
			return null;
		}

		$storageCreation = [
			'storageId' => self::$storageId,
			'mountPoint' => self::$mountPoint,
		];

		self::clear();

		return $storageCreation;
	}

	public static function clear(): void
	{
		self::$storageId = null;
		self::$mountPoint = null;
	}
}
