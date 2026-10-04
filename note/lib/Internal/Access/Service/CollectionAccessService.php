<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Access\Service;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Access\Model\CollectionAccessChange;
use Bitrix\Note\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Model\CollectionAccessTable;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\User\SystemUser;

final class CollectionAccessService
{
	public const LEVEL_NONE = 0;
	public const LEVEL_VIEW = 10;
	public const LEVEL_MANAGE = 30;
	public const LEVEL_MODERATE = 40;
	public const LEVEL_CODE_NONE = 'none';
	public const LEVEL_CODE_VIEW = 'view';
	public const LEVEL_CODE_MANAGE = 'manage';
	public const LEVEL_CODE_MODERATE = 'moderate';

	/**
	 * Access codes of one user for the lifetime of the request, keyed by user id.
	 *
	 * The codes describe WHO the user is (their groups, departments, socialnetwork roles), not what
	 * they may do in note: nothing this module writes can change them, and CAccess re-derives them
	 * from the same rows on every call, walking every auth provider (CAccess::UpdateCodes) before it
	 * reaches its own static cache. One document open used to pay that walk three times over - the
	 * caller's own access snapshot, the backlinks counter's snapshot and its source filter.
	 *
	 * @var array<int, array<int, string>>
	 */
	private static array $userAccessCodes = [];

	public static function getUserLevel(int $collectionId, int $userId, array $accessCodes): int
	{
		return self::getUserAccessSnapshot($collectionId, $userId, $accessCodes)['effective'];
	}

	/**
	 * Single-query lookup that returns both effective level for the user and the '*' policy level.
	 *
	 * @return array{effective: int, policy: int}
	 */
	public static function getUserAccessSnapshot(int $collectionId, int $userId, array $accessCodes): array
	{
		if ($collectionId <= 0 || $userId <= 0)
		{
			return ['effective' => self::LEVEL_NONE, 'policy' => self::LEVEL_NONE];
		}

		$codes = array_values(array_unique(array_filter([
			...$accessCodes,
			'U' . $userId,
			'*',
		], static fn($code) => is_string($code) && $code !== '')));

		$query = CollectionAccessTable::query()
			->setSelect(['SUBJECT_CODE', 'LEVEL'])
			->where('COLLECTION_ID', $collectionId)
			->whereIn('SUBJECT_CODE', $codes)
			->exec()
		;

		$policyLevel = self::LEVEL_NONE;
		$personalLevel = null;
		$personalDeny = false;
		while ($row = $query->fetch())
		{
			$level = (int)$row['LEVEL'];
			if ($row['SUBJECT_CODE'] === '*')
			{
				$policyLevel = $level;
			}
			elseif ($level === self::LEVEL_NONE)
			{
				$personalDeny = true;
			}
			else
			{
				$personalLevel = max($personalLevel ?? self::LEVEL_NONE, $level);
			}
		}

		$effective = $personalDeny ? self::LEVEL_NONE : ($personalLevel ?? $policyLevel);

		return ['effective' => $effective, 'policy' => $policyLevel];
	}

	/**
	 * Resolves the access snapshot for the current user with admin shortcut.
	 *
	 * Admin gets effective=MODERATE without ACL lookup, but the '*' policy still requires a SELECT
	 * so the UI can render the correct policy label.
	 *
	 * @return array{effective: int, policy: int}
	 */
	public static function getCurrentUserAccessSnapshot(int $collectionId): array
	{
		if ($collectionId <= 0)
		{
			return ['effective' => self::LEVEL_NONE, 'policy' => self::LEVEL_NONE];
		}

		if (PortalAdmin::isCurrentUserAdmin())
		{
			return [
				'effective' => self::LEVEL_MODERATE,
				'policy' => self::getCollectionPolicyLevel($collectionId),
			];
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return ['effective' => self::LEVEL_NONE, 'policy' => self::LEVEL_NONE];
		}

		$accessCodes = self::getCurrentUserAccessCodes($userId);

		return self::getUserAccessSnapshot($collectionId, $userId, $accessCodes);
	}

	public static function currentUserHasLevel(int $collectionId, int $requiredLevel): bool
	{
		if ($requiredLevel <= self::LEVEL_NONE)
		{
			return true;
		}

		$userId = (int)CurrentUser::get()->getId();

		if ($userId <= 0 || $collectionId <= 0)
		{
			return false;
		}

		if (PortalAdmin::isCurrentUserAdmin())
		{
			return true;
		}

		$accessCodes = self::getCurrentUserAccessCodes($userId);

		return self::hasCollectionLevel($collectionId, $userId, $accessCodes, $requiredLevel);
	}

	public static function hasCollectionLevel(int $collectionId, int $userId, array $accessCodes, int $requiredLevel): bool
	{
		if ($requiredLevel <= self::LEVEL_NONE)
		{
			return true;
		}

		return self::getUserLevel($collectionId, $userId, $accessCodes) >= $requiredLevel;
	}

	public static function normalizePolicyLevel(string|int|null $level): int
	{
		if (is_int($level))
		{
			return match ($level)
			{
				self::LEVEL_VIEW => self::LEVEL_VIEW,
				self::LEVEL_MANAGE => self::LEVEL_MANAGE,
				self::LEVEL_MODERATE => self::LEVEL_MODERATE,
				default => self::LEVEL_NONE,
			};
		}
		if (is_string($level) && is_numeric($level))
		{
			return self::normalizePolicyLevel((int)$level);
		}

		$normalized = mb_strtolower(trim((string)$level));

		return match ($normalized)
		{
			self::LEVEL_CODE_VIEW => self::LEVEL_VIEW,
			self::LEVEL_CODE_MANAGE => self::LEVEL_MANAGE,
			self::LEVEL_CODE_MODERATE => self::LEVEL_MODERATE,
			default => self::LEVEL_NONE,
		};
	}

	public static function normalizePermissionLevel(string|int|null $level): int
	{
		if (is_int($level))
		{
			return match ($level)
			{
				self::LEVEL_VIEW => self::LEVEL_VIEW,
				self::LEVEL_MANAGE => self::LEVEL_MANAGE,
				self::LEVEL_MODERATE => self::LEVEL_MODERATE,
				default => self::LEVEL_NONE,
			};
		}
		if (is_string($level) && is_numeric($level))
		{
			return self::normalizePermissionLevel((int)$level);
		}

		$normalized = mb_strtolower(trim((string)$level));

		return match ($normalized)
		{
			self::LEVEL_CODE_VIEW => self::LEVEL_VIEW,
			self::LEVEL_CODE_MANAGE => self::LEVEL_MANAGE,
			self::LEVEL_CODE_MODERATE => self::LEVEL_MODERATE,
			default => self::LEVEL_NONE,
		};
	}

	public static function levelToCode(int $level): string
	{
		return match ($level)
		{
			self::LEVEL_VIEW => self::LEVEL_CODE_VIEW,
			self::LEVEL_MANAGE => self::LEVEL_CODE_MANAGE,
			self::LEVEL_MODERATE => self::LEVEL_CODE_MODERATE,
			default => self::LEVEL_CODE_NONE,
		};
	}

	public static function getCollectionPolicyLevel(int $collectionId): int
	{
		if ($collectionId <= 0)
		{
			return self::LEVEL_NONE;
		}

		$row = CollectionAccessTable::query()
			->setSelect(['LEVEL'])
			->where('COLLECTION_ID', $collectionId)
			->where('SUBJECT_CODE', '*')
			->fetch()
		;

		return $row ? self::normalizePolicyLevel((int)$row['LEVEL']) : self::LEVEL_NONE;
	}

	/**
	 * Creates the default ACL rows for a freshly created collection:
	 *  - owner (`U{userId}`) gets LEVEL_MODERATE
	 *  - everyone else (`*`) gets LEVEL_NONE
	 */
	public static function createDefaultAccess(int $collectionId, int $userId): void
	{
		if ($collectionId <= 0 || $userId <= 0)
		{
			return;
		}

		CollectionAccessTable::add([
			'COLLECTION_ID' => $collectionId,
			'SUBJECT_CODE' => 'U' . $userId,
			'LEVEL' => self::LEVEL_MODERATE,
			'CREATED_BY' => $userId,
		]);

		CollectionAccessTable::add([
			'COLLECTION_ID' => $collectionId,
			'SUBJECT_CODE' => '*',
			'LEVEL' => self::LEVEL_NONE,
			'CREATED_BY' => $userId,
		]);
	}

	/**
	 * System-only ACL row writer for the '*' subject. Idempotent.
	 * CREATED_BY is set to SystemUser::ID. Use only for module bootstrap
	 * (welcome content). Do NOT call from user-facing code — use
	 * replaceCollectionPermissions() instead.
	 */
	public static function installSystemPolicy(int $collectionId, int $policyLevel): Result
	{
		$result = new Result();
		if ($collectionId <= 0)
		{
			$result->addError(new \Bitrix\Main\Error('Invalid collection id for system policy.'));

			return $result;
		}

		$normalizedLevel = self::normalizePolicyLevel($policyLevel);

		$existing = CollectionAccessTable::query()
			->setSelect(['ID', 'LEVEL'])
			->where('COLLECTION_ID', $collectionId)
			->where('SUBJECT_CODE', '*')
			->fetch()
		;

		if ($existing)
		{
			if ((int)$existing['LEVEL'] === $normalizedLevel)
			{
				PermissionDictionary::clearCollectionPermissionsCache();

				return $result;
			}

			$updateResult = CollectionAccessTable::update((int)$existing['ID'], [
				'LEVEL' => $normalizedLevel,
			]);
			if (!$updateResult->isSuccess())
			{
				$result->addErrors($updateResult->getErrors());
			}
		}
		else
		{
			$addResult = CollectionAccessTable::add([
				'COLLECTION_ID' => $collectionId,
				'SUBJECT_CODE' => '*',
				'LEVEL' => $normalizedLevel,
				'CREATED_BY' => SystemUser::ID,
			]);
			if (!$addResult->isSuccess())
			{
				$result->addErrors($addResult->getErrors());
			}
		}

		PermissionDictionary::clearCollectionPermissionsCache();

		return $result;
	}

	public static function replaceCollectionPermissions(
		int $collectionId,
		array $permissions,
		int $actorId,
		string|int $policyLevel = self::LEVEL_NONE,
		?PushNotificationService $pushService = null
	): Result
	{
		$result = new Result();
		if ($collectionId <= 0)
		{
			$result->addError(new \Bitrix\Main\Error('Invalid collection id'));

			return $result;
		}

		$normalizedPolicyLevel = self::normalizePolicyLevel($policyLevel);

		// Snapshot the existing ACL outside the transaction so the diff can fire after commit.
		$oldRows = self::fetchAclMap($collectionId);

		$prepared = [];
		$prepared['*'] = [
			'COLLECTION_ID' => $collectionId,
			'SUBJECT_CODE' => '*',
			'LEVEL' => $normalizedPolicyLevel,
			'CREATED_BY' => $actorId,
		];

		foreach ($permissions as $permission)
		{
			$subjectCode = (string)($permission['subjectCode'] ?? '');
			$level = self::normalizePermissionLevel($permission['level'] ?? self::LEVEL_NONE);

			if (!AccessCode::isValid($subjectCode))
			{
				continue;
			}

			if (!in_array($level, [self::LEVEL_NONE, self::LEVEL_VIEW, self::LEVEL_MANAGE, self::LEVEL_MODERATE], true))
			{
				continue;
			}

			$prepared[$subjectCode] = [
				'COLLECTION_ID' => $collectionId,
				'SUBJECT_CODE' => $subjectCode,
				'LEVEL' => $level,
				'CREATED_BY' => $actorId,
			];
		}

		$hasModerator = false;
		foreach ($prepared as $row)
		{
			if ((int)$row['LEVEL'] === self::LEVEL_MODERATE)
			{
				$hasModerator = true;
				break;
			}
		}
		if (!$hasModerator)
		{
			$result->addError(new \Bitrix\Main\Error(
				'Collection must have at least one moderator',
				'NOTE_COLLECTION_NO_MODERATOR'
			));

			return $result;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		$committed = false;
		try
		{
			$updateCollectionResult = CollectionTable::update($collectionId, [
				'UPDATED_BY' => $actorId,
			]);
			if (!$updateCollectionResult->isSuccess())
			{
				$result->addErrors($updateCollectionResult->getErrors());
				$connection->rollbackTransaction();

				return $result;
			}

			$deleteResult = self::deleteCollectionPermissions($collectionId);
			if (!$deleteResult->isSuccess())
			{
				$result->addErrors($deleteResult->getErrors());
				$connection->rollbackTransaction();

				return $result;
			}

			if (!empty($prepared))
			{
				$sqlHelper = $connection->getSqlHelper();
				$values = [];
				foreach ($prepared as $row)
				{
					$values[] = sprintf(
						"(%s, %d, '%s', %d, %d)",
						$sqlHelper->convertToDbDateTime(new \Bitrix\Main\Type\DateTime()),
						$collectionId,
						$sqlHelper->forSql((string)$row['SUBJECT_CODE']),
						(int)$row['LEVEL'],
						(int)$row['CREATED_BY'],
					);
				}

				$connection->queryExecute(
					'INSERT INTO b_note_collection_access'
					. ' (CREATED_AT, COLLECTION_ID, SUBJECT_CODE, LEVEL, CREATED_BY) VALUES '
					. implode(', ', $values)
				);
			}

			$connection->commitTransaction();
			$committed = true;
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			$result->addError(new \Bitrix\Main\Error($e->getMessage()));
		}

		PermissionDictionary::clearCollectionPermissionsCache();

		if ($committed && $oldRows !== [])
		{
			// Initial ACL bootstrap (oldRows empty) is covered by collectionCreate emission;
			// no one is subscribed to NOTE_COLLECTION_{id}_ACL yet anyway.
			$newRows = self::projectAclMap($prepared);
			$change = CollectionAccessChange::lexical($oldRows, $newRows);
			self::dispatchAccessChange($collectionId, $change, $pushService);
		}

		return $result;
	}

	/**
	 * @return array<string, int> SUBJECT_CODE => LEVEL
	 */
	private static function fetchAclMap(int $collectionId): array
	{
		$query = CollectionAccessTable::query()
			->setSelect(['SUBJECT_CODE', 'LEVEL'])
			->where('COLLECTION_ID', $collectionId)
			->exec()
		;

		$map = [];
		while ($row = $query->fetch())
		{
			$code = (string)$row['SUBJECT_CODE'];
			if ($code !== '')
			{
				$map[$code] = (int)$row['LEVEL'];
			}
		}

		return $map;
	}

	/**
	 * @param array<string, array{LEVEL: int, SUBJECT_CODE: string, ...}> $prepared
	 * @return array<string, int>
	 */
	private static function projectAclMap(array $prepared): array
	{
		$map = [];
		foreach ($prepared as $code => $row)
		{
			$code = (string)$code;
			if ($code !== '')
			{
				$map[$code] = (int)$row['LEVEL'];
			}
		}

		return $map;
	}

	private static function dispatchAccessChange(int $collectionId, CollectionAccessChange $change, ?PushNotificationService $pushService): void
	{
		if (!Configuration::isAccessCascadeBroadcastEnabled())
		{
			return;
		}

		$push = $pushService ?? new PushNotificationService();
		$maybeBoundary = $change->maybeBoundary();
		$push->dispatchAfterCommit(static function () use ($push, $collectionId, $maybeBoundary): void {
			$push->sendToCollectionAcl($collectionId, 'collectionCapabilities', [
				'collectionId' => $collectionId,
			]);
			if ($maybeBoundary)
			{
				$push->sendGlobal('collectionListInvalidated', [
					'collectionId' => $collectionId,
				]);
			}
		});
	}

	public static function getCollectionPermissions(int $collectionId): array
	{
		if ($collectionId <= 0)
		{
			return [];
		}

		$accessItems = CollectionAccessTable::getList([
			'select' => ['SUBJECT_CODE', 'LEVEL'],
			'filter' => [
				'=COLLECTION_ID' => $collectionId,
				'!=SUBJECT_CODE' => '*',
			],
			'order' => ['SUBJECT_CODE' => 'ASC'],
		])->fetchCollection();

		return array_map(static fn($item): array => [
			'subjectCode' => (string)$item->getSubjectCode(),
			'level' => self::levelToCode((int)$item->getLevel()),
		], $accessItems->getAll());
	}

	public static function buildUserAccessCodes(int $userId): array
	{
		if ($userId <= 0 || !class_exists('\CAccess'))
		{
			return [];
		}

		if (isset(self::$userAccessCodes[$userId]))
		{
			return self::$userAccessCodes[$userId];
		}

		$codes = \CAccess::getUserCodesArray($userId);
		$codes = is_array($codes) ? $codes : [];
		$codes[] = 'U' . $userId;

		self::$userAccessCodes[$userId] = array_values(array_unique(array_filter(
			$codes,
			static fn($code) => is_string($code) && $code !== '',
		)));

		return self::$userAccessCodes[$userId];
	}

	/**
	 * Drops the request-lifetime code cache. Production has nothing to call this - the codes cannot
	 * change under a running request - but a test that swaps the current user or grants a group
	 * membership mid-run has to, exactly as it already clears UserAccessItem's own cache.
	 */
	public static function clearUserAccessCodesCache(): void
	{
		self::$userAccessCodes = [];
	}

	public static function batchGetUserLevels(array $collectionIds, array $accessCodes): array
	{
		if (empty($collectionIds) || empty($accessCodes))
		{
			return [];
		}

		$query = CollectionAccessTable::query()
			->setSelect(['COLLECTION_ID', 'SUBJECT_CODE', 'LEVEL'])
			->whereIn('COLLECTION_ID', $collectionIds)
			->whereIn('SUBJECT_CODE', $accessCodes)
			->exec()
		;

		$personal = [];
		$policy = [];
		$deny = [];
		while ($row = $query->fetch())
		{
			$cid = (int)$row['COLLECTION_ID'];
			$level = (int)$row['LEVEL'];
			if ($row['SUBJECT_CODE'] === '*')
			{
				$policy[$cid] = $level;
			}
			elseif ($level === self::LEVEL_NONE)
			{
				$deny[$cid] = true;
			}
			else
			{
				$personal[$cid] = max($personal[$cid] ?? self::LEVEL_NONE, $level);
			}
		}

		$result = [];
		foreach ($collectionIds as $cid)
		{
			$cid = (int)$cid;
			if (!empty($deny[$cid]))
			{
				$result[$cid] = self::LEVEL_NONE;
			}
			elseif (array_key_exists($cid, $personal))
			{
				$result[$cid] = $personal[$cid];
			}
			elseif (array_key_exists($cid, $policy))
			{
				$result[$cid] = $policy[$cid];
			}
		}

		return $result;
	}

	public static function getAllUserLevels(array $accessCodes): array
	{
		if (empty($accessCodes))
		{
			return ['effective' => [], 'policy' => []];
		}

		$codes = array_values(array_unique(array_merge($accessCodes, ['*'])));

		$query = CollectionAccessTable::query()
			->setSelect(['COLLECTION_ID', 'SUBJECT_CODE', 'LEVEL'])
			->whereIn('SUBJECT_CODE', $codes)
			->exec()
		;

		$explicit = [];
		$policy = [];
		$deny = [];
		while ($row = $query->fetch())
		{
			$cid = (int)$row['COLLECTION_ID'];
			$level = (int)$row['LEVEL'];
			if ($row['SUBJECT_CODE'] === '*')
			{
				$policy[$cid] = $level;
			}
			elseif ($level === self::LEVEL_NONE)
			{
				$deny[$cid] = true;
			}
			else
			{
				$explicit[$cid] = max($explicit[$cid] ?? self::LEVEL_NONE, $level);
			}
		}

		$effective = [];
		foreach ($explicit as $cid => $level)
		{
			$effective[$cid] = $level;
		}
		foreach ($policy as $cid => $level)
		{
			if (!isset($effective[$cid]))
			{
				$effective[$cid] = $level;
			}
		}
		foreach ($deny as $cid => $_)
		{
			$effective[$cid] = self::LEVEL_NONE;
		}

		return ['effective' => $effective, 'policy' => $policy];
	}

	public static function userHasManageableCollection(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (PortalAdmin::isAdmin($userId))
		{
			$row = CollectionTable::query()
				->setSelect(['ID'])
				->where('IS_ARCHIVED', 'N')
				->setLimit(1)
				->exec()
				->fetch()
			;

			return !empty($row);
		}

		$accessCodes = self::buildUserAccessCodes($userId);
		if (empty($accessCodes))
		{
			return false;
		}

		$levels = self::getAllUserLevels($accessCodes);
		foreach ($levels['effective'] ?? [] as $level)
		{
			if ((int)$level >= self::LEVEL_MANAGE)
			{
				return true;
			}
		}

		return false;
	}

	private static function getCurrentUserAccessCodes(int $userId): array
	{
		if ($userId <= 0 || !class_exists('\CAccess'))
		{
			return [];
		}

		$codes = \CAccess::getUserCodesArray($userId);

		return is_array($codes) ? $codes : [];
	}

	private static function deleteCollectionPermissions(int $collectionId): Result
	{
		$result = new Result();

		CollectionAccessTable::deleteByFilter(['=COLLECTION_ID' => $collectionId]);

		return $result;
	}
}
