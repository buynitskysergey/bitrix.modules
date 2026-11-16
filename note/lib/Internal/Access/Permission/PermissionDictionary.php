<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Access\Permission;

use Bitrix\Main\Access\Permission\PermissionDictionary as MainPermissionDictionary;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Model\CollectionTable;

final class PermissionDictionary extends MainPermissionDictionary
{
	public const NOTE_ACCESS = 1;
	public const NOTE_EDIT_PERMISSIONS = 2;
	public const NOTE_CREATE_COLLECTIONS = 3;
	public const NOTE_IMPORT = 4;

	public const COLLECTION_PERMISSION_PREFIX = 'C';
	public const COLLECTION_LEVEL_NONE = 0;
	public const COLLECTION_LEVEL_VIEW = 10;
	public const COLLECTION_LEVEL_MANAGE = 30;
	public const COLLECTION_LEVEL_MODERATE = 40;

	private static ?array $collectionPermissions = null;

	public static function getPermission($permissionId): array
	{
		if (is_string($permissionId) && self::isCollectionPermission($permissionId))
		{
			if (self::$collectionPermissions !== null && isset(self::$collectionPermissions[$permissionId]))
			{
				return self::$collectionPermissions[$permissionId];
			}

			return self::buildCollectionPermissionDescriptor($permissionId);
		}

		$permission = parent::getPermission($permissionId);

		switch ((int)$permissionId)
		{
			case self::NOTE_ACCESS:
				$permission['title'] = Loc::getMessage('NOTE_PERMISSION_ACCESS_TITLE');
				$permission['hint'] = Loc::getMessage('NOTE_PERMISSION_ACCESS_HINT');
				break;
			case self::NOTE_EDIT_PERMISSIONS:
				$permission['title'] = Loc::getMessage('NOTE_PERMISSION_EDIT_PERMISSIONS_TITLE');
				$permission['hint'] = Loc::getMessage('NOTE_PERMISSION_EDIT_PERMISSIONS_HINT');
				break;
			case self::NOTE_CREATE_COLLECTIONS:
				$permission['title'] = Loc::getMessage('NOTE_PERMISSION_CREATE_COLLECTIONS_TITLE');
				$permission['hint'] = Loc::getMessage('NOTE_PERMISSION_CREATE_COLLECTIONS_HINT');
				break;
			case self::NOTE_IMPORT:
				$permission['title'] = Loc::getMessage('NOTE_PERMISSION_IMPORT_TITLE');
				$permission['hint'] = Loc::getMessage('NOTE_PERMISSION_IMPORT_HINT');
				break;
		}

		if (($permission['type'] ?? null) === self::TYPE_TOGGLER)
		{
			$permission['minValue'] = '0';
			$permission['maxValue'] = '1';
		}

		return $permission;
	}

	public static function getDefaultPermissionValue($permissionId): int
	{
		if ((int)$permissionId === self::NOTE_EDIT_PERMISSIONS)
		{
			return self::VALUE_NO;
		}

		if ((int)$permissionId === self::NOTE_IMPORT)
		{
			return self::VALUE_NO;
		}

		return self::VALUE_YES;
	}

	public static function getCollectionPermissionId(int $collectionId): string
	{
		return self::COLLECTION_PERMISSION_PREFIX . $collectionId;
	}

	public static function getCollectionIdFromPermission(string $permissionId): int
	{
		return (int)mb_substr($permissionId, 1);
	}

	public static function isCollectionPermission(string $permissionId): bool
	{
		return str_starts_with($permissionId, self::COLLECTION_PERMISSION_PREFIX);
	}

	public static function clearCollectionPermissionsCache(): void
	{
		self::$collectionPermissions = null;
	}

	public static function getCollectionPermissions(): array
	{
		if (self::$collectionPermissions !== null)
		{
			return self::$collectionPermissions;
		}

		self::$collectionPermissions = [];
		// Archived bases stay on the screen - rights over them still work; a base in the recycle bin
		// is not there to be granted anything.
		$collections = CollectionTable::getList([
			'select' => ['ID', 'NAME'],
			'filter' => ['=TRASHED_AT' => null],
		])->fetchCollection();

		foreach ($collections as $item)
		{
			$id = self::getCollectionPermissionId((int)$item->getId());
			self::$collectionPermissions[$id] = self::buildCollectionPermissionDescriptor($id, (string)$item->getName());
		}

		return self::$collectionPermissions;
	}

	private static function buildCollectionPermissionDescriptor(string $permissionId, ?string $title = null): array
	{
		$separator = '|';
		$allSelectedKey = implode($separator, self::getCollectionPermissionCodes());

		return [
			'id' => $permissionId,
			'title' => $title ?? '',
			'type' => self::TYPE_DEPENDENT_VARIABLES,
			'variables' => self::getCollectionVariables(),
			'minValue' => [self::COLLECTION_LEVEL_NONE],
			'maxValue' => self::getCollectionPermissionCodes(),
			'selectedVariablesAliases' => [
				'separator' => $separator,
				$allSelectedKey => Loc::getMessage('NOTE_PERMISSION_COLLECTION_LEVEL_ALL'),
			],
			'emptyValue' => self::COLLECTION_LEVEL_NONE,
			'groupHead' => false,
			'dependentVariablesPopupHint' => Loc::getMessage('NOTE_PERMISSION_COLLECTION_HINT'),
		];
	}

	private static function getCollectionPermissionCodes(): array
	{
		return [
			self::COLLECTION_LEVEL_VIEW,
			self::COLLECTION_LEVEL_MANAGE,
			self::COLLECTION_LEVEL_MODERATE,
		];
	}

	private static function getCollectionVariables(): array
	{
		$codes = self::getCollectionPermissionCodes();

		return [
			[
				'id' => self::COLLECTION_LEVEL_NONE,
				'title' => Loc::getMessage('NOTE_PERMISSION_COLLECTION_LEVEL_NONE'),
				'useAsNothingSelectedInSubsection' => true,
				'useAsEmpty' => true,
				'conflictsWith' => $codes,
			],
			[
				'id' => self::COLLECTION_LEVEL_VIEW,
				'title' => Loc::getMessage('NOTE_PERMISSION_COLLECTION_LEVEL_VIEW'),
			],
			[
				'id' => self::COLLECTION_LEVEL_MANAGE,
				'title' => Loc::getMessage('NOTE_PERMISSION_COLLECTION_LEVEL_MANAGE'),
				'requires' => [self::COLLECTION_LEVEL_VIEW],
			],
			[
				'id' => self::COLLECTION_LEVEL_MODERATE,
				'title' => Loc::getMessage('NOTE_PERMISSION_COLLECTION_LEVEL_MODERATE'),
				'requires' => [self::COLLECTION_LEVEL_VIEW, self::COLLECTION_LEVEL_MANAGE],
			],
		];
	}
}
