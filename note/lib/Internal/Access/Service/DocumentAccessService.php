<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Access\Service;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserAccessTable;
use Bitrix\Note\Internal\Access\PortalAdmin;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Entity\RecycleBin\RecycleBinRecord;
use Bitrix\Note\Internal\Model\CollectionTable;
use Bitrix\Note\Internal\Model\DocumentAccessTable;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\EventTable;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Infrastructure\Agent\Access\SubtreeAclReconcileScheduler;
use Bitrix\Note\Internal\Service\Access\SubtreeAclReconciler;
use Bitrix\Note\Internal\Service\Collaboration\PushNotificationService;
use Bitrix\Note\Internal\Service\History\EventLogService;

final class DocumentAccessService
{
	public const LEVEL_NONE = 0;
	public const LEVEL_VIEW = 10;
	public const LEVEL_EDIT = 20;
	public const LEVEL_CODE_NONE = 'none';
	public const LEVEL_CODE_VIEW = 'view';
	public const LEVEL_CODE_EDIT = 'edit';

	// Single source of truth for "no inheritance source" on an ACL row (b_note_document_access.SOURCE_DOCUMENT_ID).
	public const SOURCE_NONE = 0;

	// Grant scope for future read/write contracts: this document only vs. this document and its subtree.
	public const SCOPE_DOCUMENT = 'document';
	public const SCOPE_SUBTREE = 'subtree';

	private const ALLOWED_LEVELS = [self::LEVEL_NONE, self::LEVEL_VIEW, self::LEVEL_EDIT];

	// [EVENT-01] Single pull command for both cascade delivery paths (collection + personal channel).
	private const COMMAND_ACCESS_CASCADE = PushNotificationService::COMMAND_ACCESS_CASCADE;

	// Bounds the personal-channel fan-out when a subtree grant targets a huge group/department.
	private const MAX_PERSONAL_RECIPIENTS = 5000;

	// Bounds the per-code membership expansion used for deny arithmetic. Held in memory as a
	// code => users map, so unlike the merged expansion it cannot stop at the recipient cap; without
	// its own bound a department code next to a deny would read the whole portal's memberships. Set
	// well above MAX_PERSONAL_RECIPIENTS because one user may appear under several codes.
	private const MAX_CODE_EXPANSION_ROWS = 20000;

	private const ERROR_INVALID_SCOPE_LEVEL = 'NOTE_ACCESS_INVALID_SCOPE_LEVEL';
	private const ERROR_SUBTREE_DISABLED = 'NOTE_ACCESS_SUBTREE_DISABLED';

	private static ?array $userHasAnyGrantCache = null;

	/** @var array<int, bool> request-scoped IS_MAIN cache, keyed by documentId */
	private static array $isMainDocumentCache = [];

	public static function normalizeLevel(string|int|null $level): int
	{
		if (is_int($level))
		{
			return in_array($level, self::ALLOWED_LEVELS, true) ? $level : self::LEVEL_NONE;
		}

		if (is_string($level) && is_numeric($level))
		{
			return self::normalizeLevel((int)$level);
		}

		$normalized = mb_strtolower(trim((string)$level));

		return match ($normalized)
		{
			self::LEVEL_CODE_VIEW => self::LEVEL_VIEW,
			self::LEVEL_CODE_EDIT => self::LEVEL_EDIT,
			default => self::LEVEL_NONE,
		};
	}

	public static function levelToCode(int $level): string
	{
		return match ($level)
		{
			self::LEVEL_VIEW => self::LEVEL_CODE_VIEW,
			self::LEVEL_EDIT => self::LEVEL_CODE_EDIT,
			default => self::LEVEL_CODE_NONE,
		};
	}

	public static function getDocumentLevel(int $documentId, int $userId, array $accessCodes): int
	{
		if ($documentId <= 0 || $userId <= 0)
		{
			return self::LEVEL_NONE;
		}

		$codes = array_values(array_unique(array_filter([
			...$accessCodes,
			'U' . $userId,
		], static fn($code) => is_string($code) && $code !== '' && $code !== '*')));

		$query = DocumentAccessTable::query()
			->setSelect(['LEVEL', 'SOURCE_DOCUMENT_ID'])
			->where('DOCUMENT_ID', $documentId)
			->whereIn('SUBJECT_CODE', $codes)
			->exec()
		;

		$rows = [];
		while ($row = $query->fetch())
		{
			$rows[] = ['level' => (int)$row['LEVEL'], 'source' => (int)$row['SOURCE_DOCUMENT_ID']];
		}

		return self::reduceDocumentLevel($rows);
	}

	/**
	 * [ALG-01] Aggregates one subject's ACL rows on a single document into its document-level.
	 * Single source of truth for the formula — shared by getDocumentLevel() and
	 * batchGetEffectiveLevels().
	 *
	 * Rows split into explicit (SOURCE_DOCUMENT_ID == SOURCE_NONE) and inherited (source != 0).
	 * An explicit deny (LEVEL_NONE) collapses ONLY the explicit contribution to 0; inheritance is
	 * hard — inheritedLevel enters the result as a separate max branch and a local explicit none
	 * cannot revoke it. With no inherited rows, documentLevel == explicitLevel (prior semantics).
	 *
	 * @param array<int, array{level: int, source: int}> $rows
	 */
	public static function reduceDocumentLevel(array $rows): int
	{
		$explicitLevel = self::LEVEL_NONE;
		$explicitDeny = false;
		$inheritedLevel = self::LEVEL_NONE;

		foreach ($rows as $row)
		{
			$level = (int)$row['level'];
			if ((int)$row['source'] === self::SOURCE_NONE)
			{
				if ($level === self::LEVEL_NONE)
				{
					$explicitDeny = true;
				}
				else
				{
					$explicitLevel = max($explicitLevel, $level);
				}
			}
			else
			{
				// Inherited rows never carry a deny by construction; LEVEL_NONE would just add 0.
				$inheritedLevel = max($inheritedLevel, $level);
			}
		}

		if ($explicitDeny)
		{
			$explicitLevel = self::LEVEL_NONE;
		}

		return max($explicitLevel, $inheritedLevel);
	}

	public static function getEffectiveLevel(int $documentId, int $collectionId, int $userId, array $accessCodes): int
	{
		$levels = self::getAccessLevels($documentId, $collectionId, $userId, $accessCodes);

		return max($levels['collection'], $levels['document']);
	}

	/**
	 * Returns the (collection, document) pair of raw levels for a single user in one
	 * shot: two SELECTs (collection_access + document_access). Used where a caller
	 * needs both axes (e.g. effective `max()` plus `sharedAccess = col < VIEW`) and
	 * does not want to re-query the same rows twice.
	 *
	 * @return array{collection: int, document: int}
	 */
	public static function getAccessLevels(int $documentId, int $collectionId, int $userId, array $accessCodes): array
	{
		$collectionLevel = $collectionId > 0
			? CollectionAccessService::getUserLevel($collectionId, $userId, $accessCodes)
			: self::LEVEL_NONE;

		$documentLevel = self::getDocumentLevel($documentId, $userId, $accessCodes);

		return [
			'collection' => $collectionLevel,
			'document' => $documentLevel,
		];
	}

	/**
	 * Single-shot ACL snapshot for the current user against a (doc, col) pair.
	 * Costs at most two SELECTs (collection_access + document_access) for non-admins,
	 * and zero queries for admins / anonymous. Reuse it whenever a single request path
	 * needs several derived flags for the same document — avoids running multiple
	 * independent currentUserHasLevel checks against the same rows.
	 *
	 * Field semantics:
	 *  - canViewCollection / canEditCollection — strictly the collection-level
	 *    grants of the viewer. Use for tree/manage decisions.
	 *  - canManagePermissions — viewer has MODERATE on the collection.
	 *  - canView / canEdit — effective grants on the document
	 *    (max(collection, document)). Use for read/write of the document itself.
	 *  - sharedAccess — true when the viewer has no collection VIEW; the document is
	 *    reachable only via a document-level grant.
	 *
	 * @return array{
	 *   canViewCollection: bool,
	 *   canEditCollection: bool,
	 *   canManagePermissions: bool,
	 *   canView: bool,
	 *   canEdit: bool,
	 *   sharedAccess: bool,
	 * }
	 */
	public static function getCurrentUserSnapshot(int $documentId, int $collectionId, bool $isArchived = false): array
	{
		if (PortalAdmin::isCurrentUserAdmin())
		{
			return [
				'canViewCollection' => true,
				'canEditCollection' => true,
				'canManagePermissions' => true,
				'canView' => true,
				'canEdit' => !$isArchived,
				'sharedAccess' => false,
			];
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0 || $documentId <= 0 || $collectionId <= 0)
		{
			return [
				'canViewCollection' => false,
				'canEditCollection' => false,
				'canManagePermissions' => false,
				'canView' => false,
				'canEdit' => false,
				'sharedAccess' => true,
			];
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = self::getAccessLevels($documentId, $collectionId, $userId, $accessCodes);
		$effective = max($levels['collection'], $levels['document']);

		return [
			'canViewCollection' => $levels['collection'] >= CollectionAccessService::LEVEL_VIEW,
			'canEditCollection' => $levels['collection'] >= CollectionAccessService::LEVEL_MANAGE,
			'canManagePermissions' => $levels['collection'] >= CollectionAccessService::LEVEL_MODERATE,
			'canView' => $effective >= self::LEVEL_VIEW,
			'canEdit' => !$isArchived && self::allowsWrite($documentId, $levels),
			'sharedAccess' => $levels['collection'] < CollectionAccessService::LEVEL_VIEW,
		];
	}

	public static function currentUserHasLevel(int $documentId, int $collectionId, int $requiredLevel): bool
	{
		if ($requiredLevel <= self::LEVEL_NONE)
		{
			return true;
		}

		$userId = (int)CurrentUser::get()->getId();

		if ($userId <= 0 || $documentId <= 0)
		{
			return false;
		}

		if (PortalAdmin::isCurrentUserAdmin())
		{
			return true;
		}

		$accessCodes = self::getCurrentUserAccessCodes($userId);
		$levels = self::getAccessLevels($documentId, $collectionId, $userId, $accessCodes);

		if ($requiredLevel >= self::LEVEL_EDIT)
		{
			return self::allowsWrite($documentId, $levels);
		}

		return max($levels['collection'], $levels['document']) >= $requiredLevel;
	}

	/**
	 * Write access to a document. Ordinary documents go by the effective level
	 * (max of the collection and document grants); a collection's main document — the
	 * knowledge base description — is writable only by a knowledge base administrator,
	 * i.e. MODERATE on the collection. Readers with EDIT (and document-level grants,
	 * which the description never has anyway) only view it.
	 *
	 * @param array{collection: int, document: int} $levels
	 */
	private static function allowsWrite(int $documentId, array $levels): bool
	{
		if (self::isMainDocument($documentId))
		{
			return $levels['collection'] >= CollectionAccessService::LEVEL_MODERATE;
		}

		return max($levels['collection'], $levels['document']) >= self::LEVEL_EDIT;
	}

	/**
	 * Costs one PK lookup per document per request; the answer never changes within a hit.
	 */
	private static function isMainDocument(int $documentId): bool
	{
		if ($documentId <= 0)
		{
			return false;
		}

		if (array_key_exists($documentId, self::$isMainDocumentCache))
		{
			return self::$isMainDocumentCache[$documentId];
		}

		$row = DocumentTable::query()
			->setSelect(['ID'])
			->where('ID', $documentId)
			->where('IS_MAIN', DocumentTable::IS_MAIN_YES)
			->setLimit(1)
			->fetch()
		;

		return self::$isMainDocumentCache[$documentId] = ($row !== false);
	}

	/**
	 * [P3.T1 / API-01] Source-aware full replacement of a document's manageable ACL.
	 *
	 * The incoming list is the complete desired state of the two MUTABLE row kinds on this
	 * document — ordinary explicit grants (scope 'document', SOURCE_NONE) and subtree markers
	 * (scope 'subtree', SOURCE == documentId). Derived rows inherited from an ancestor
	 * (SOURCE == ancestor id) are read-only and are NEVER touched here: the full-replace deletes
	 * only SOURCE_NONE rows, so editing a descendant's own permissions cannot revoke the access an
	 * active ancestor source pushed down onto it.
	 *
	 * @param array<int, array{subjectCode: string, level: string|int, scope?: string}> $permissions
	 */
	public static function replaceDocumentPermissions(
		int $documentId,
		array $permissions,
		int $actorId,
		?PushNotificationService $pushService = null
	): Result
	{
		$result = new Result();
		if ($documentId <= 0)
		{
			$result->addError(new \Bitrix\Main\Error('Invalid document id'));

			return $result;
		}

		// Desired end-state, split by scope. documentGrants → SOURCE_NONE rows (level may be NONE =
		// explicit deny); subtreeGrants → markers (positive level only).
		$documentGrants = [];
		$subtreeGrants = [];
		foreach ($permissions as $permission)
		{
			$subjectCode = (string)($permission['subjectCode'] ?? '');
			$level = self::normalizeLevel($permission['level'] ?? self::LEVEL_NONE);
			$scope = self::normalizeScope($permission['scope'] ?? self::SCOPE_DOCUMENT);

			if ($subjectCode === '*' || !AccessCode::isValid($subjectCode))
			{
				continue;
			}

			if ($scope === self::SCOPE_SUBTREE)
			{
				if ($level === self::LEVEL_NONE)
				{
					// "none + subtree" is not a subtree-wide deny — inheritance carries access, not
					// its absence — so the combination is rejected rather than silently downgraded.
					$result->addError(new \Bitrix\Main\Error(
						'Subtree scope requires a positive level',
						self::ERROR_INVALID_SCOPE_LEVEL,
					));

					return $result;
				}
				$subtreeGrants[$subjectCode] = $level;
			}
			else
			{
				$documentGrants[$subjectCode] = $level;
			}
		}

		// A subject may not hold a marker and an ordinary explicit row simultaneously; the subtree
		// scope wins if a malformed payload lists the same subject twice.
		foreach (array_keys($subtreeGrants) as $subjectCode)
		{
			unset($documentGrants[$subjectCode]);
		}

		$oldExplicit = self::fetchGrantsBySource($documentId, self::SOURCE_NONE);
		$oldMarkers = self::fetchGrantsBySource($documentId, $documentId);

		// Creating a NEW subtree grant (a marker where the subject had none) is gated by the flag.
		// Keeping, re-levelling or revoking an existing marker stays always-on.
		if (!empty($subtreeGrants) && !Configuration::isSubtreeInheritanceEnabled())
		{
			foreach ($subtreeGrants as $subjectCode => $level)
			{
				if (!isset($oldMarkers[$subjectCode]))
				{
					$result->addError(new \Bitrix\Main\Error(
						'Subtree inheritance is not available',
						self::ERROR_SUBTREE_DISABLED,
					));

					return $result;
				}
			}
		}

		ksort($documentGrants);
		ksort($subtreeGrants);
		$explicitChanged = $oldExplicit !== $documentGrants;
		$markersChanged = $oldMarkers !== $subtreeGrants;
		$grantsChanged = $explicitChanged || $markersChanged;

		$affectedSubjects = self::collectAffectedSubjects($oldExplicit, $documentGrants, $oldMarkers, $subtreeGrants);

		$hadAclBefore = self::hasAnyAclRow($documentId);
		$hasAclAfter = !empty($documentGrants) || !empty($subtreeGrants);
		$collectionId = (int)(self::resolveDocumentCollectionId($documentId) ?? 0);

		$connection = Application::getConnection();
		$connection->startTransaction();
		$committed = false;
		$affectedDocumentIds = [$documentId];
		try
		{
			// Replace ordinary explicit rows only (also sweeps the legacy '*' policy row, SOURCE_NONE).
			DocumentAccessTable::deleteByFilter([
				'=DOCUMENT_ID' => $documentId,
				'=SOURCE_DOCUMENT_ID' => self::SOURCE_NONE,
			]);
			if (!empty($documentGrants))
			{
				self::insertExplicitRows($documentId, $documentGrants, $actorId);
			}

			// Markers alone decide what this source pushes down, so an unchanged marker set means an
			// unchanged target: convergeMarkers writes nothing and reconcile walks the whole subtree
			// only to find no diff. An explicit-only save (the common case on a document that also
			// shares its subtree) must not pay for that walk.
			if ($markersChanged)
			{
				self::convergeMarkers($connection, $documentId, $subtreeGrants, $actorId);

				if ($collectionId > 0)
				{
					// A subtree grant to a huge audience can exceed the sync widen threshold; the queue
					// sink hands that widen to the durable agent (P4.T6). Narrowing stays synchronous.
					$descendantIds = (new SubtreeAclReconciler(null, new SubtreeAclReconcileScheduler()))
						->reconcile($documentId, $collectionId);
					foreach ($descendantIds as $descId)
					{
						$affectedDocumentIds[] = (int)$descId;
					}
				}
			}

			// Fires only when the manageable grant set actually changed — an idempotent re-save must
			// not add a history event.
			if ($grantsChanged && Configuration::isActivityEnabled())
			{
				(new EventLogService())->record(EventTable::SCOPE_DOCUMENT, $documentId, 'access_changed', $actorId);
			}

			$connection->commitTransaction();
			$committed = true;
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			$result->addError(new \Bitrix\Main\Error($e->getMessage()));
		}

		self::$userHasAnyGrantCache = null;

		if ($committed)
		{
			if ($hadAclBefore || $hasAclAfter)
			{
				// Root editor refetches its own capabilities (EVENT contract unchanged by EVENT-01).
				self::dispatchDocumentCapabilities($documentId, $pushService);
			}

			if ($grantsChanged && $collectionId > 0)
			{
				self::dispatchAccessCascade(
					$collectionId,
					array_values(array_unique($affectedDocumentIds)),
					array_keys($affectedSubjects),
					$pushService,
				);
			}
		}

		return $result;
	}

	private static function normalizeScope(string|null $scope): string
	{
		return mb_strtolower(trim((string)$scope)) === self::SCOPE_SUBTREE
			? self::SCOPE_SUBTREE
			: self::SCOPE_DOCUMENT;
	}

	/**
	 * @return array<string, int> subjectCode => level for rows of the given source (excludes '*'),
	 *                             sorted by key for order-independent diffing
	 */
	private static function fetchGrantsBySource(int $documentId, int $source): array
	{
		$rows = DocumentAccessTable::query()
			->setSelect(['SUBJECT_CODE', 'LEVEL'])
			->where('DOCUMENT_ID', $documentId)
			->where('SOURCE_DOCUMENT_ID', $source)
			->where('SUBJECT_CODE', '!=', '*')
			->fetchAll();

		$map = [];
		foreach ($rows as $row)
		{
			$map[(string)$row['SUBJECT_CODE']] = (int)$row['LEVEL'];
		}
		ksort($map);

		return $map;
	}

	private static function insertExplicitRows(int $documentId, array $grants, int $actorId): void
	{
		$now = new DateTime();
		$rows = [];
		foreach ($grants as $subjectCode => $level)
		{
			$rows[] = [
				'CREATED_AT' => $now,
				'DOCUMENT_ID' => $documentId,
				'SUBJECT_CODE' => (string)$subjectCode,
				'LEVEL' => (int)$level,
				'SOURCE_DOCUMENT_ID' => self::SOURCE_NONE,
				'CREATED_BY' => $actorId,
			];
		}

		DocumentAccessTable::addMulti($rows, true);
	}

	/**
	 * [ALG-03] Converge subtree markers on the root to the desired set: drop markers whose subject
	 * left the set, UPSERT the rest (cross-DB, LEVEL-only on conflict).
	 *
	 * @param array<string, int> $newMarkers subjectCode => level
	 */
	private static function convergeMarkers($connection, int $documentId, array $newMarkers, int $actorId): void
	{
		// The '*' marker is never produced by this path ('*' is rejected upstream), but both the
		// empty and the non-empty converge must treat it the same way — never sweep it.
		$staleFilter = [
			'=DOCUMENT_ID' => $documentId,
			'=SOURCE_DOCUMENT_ID' => $documentId,
			'!=SUBJECT_CODE' => '*',
		];
		if (!empty($newMarkers))
		{
			$staleFilter['!@SUBJECT_CODE'] = array_keys($newMarkers);
		}
		DocumentAccessTable::deleteByFilter($staleFilter);

		if (empty($newMarkers))
		{
			return;
		}

		$sqlHelper = $connection->getSqlHelper();
		$now = new DateTime();
		$rows = [];
		foreach ($newMarkers as $subjectCode => $level)
		{
			$rows[] = [
				'DOCUMENT_ID' => $documentId,
				'SUBJECT_CODE' => (string)$subjectCode,
				'LEVEL' => (int)$level,
				'SOURCE_DOCUMENT_ID' => $documentId,
				'CREATED_BY' => $actorId,
				'CREATED_AT' => $now,
			];
		}

		$sql = $sqlHelper->prepareMergeValues(
			DocumentAccessTable::getTableName(),
			['DOCUMENT_ID', 'SUBJECT_CODE', 'SOURCE_DOCUMENT_ID'],
			$rows,
			['LEVEL'],
		);
		if ($sql !== '')
		{
			$connection->queryExecute($sql);
		}
	}

	/**
	 * Subjects whose grant changed across explicit and marker rows — a safe superset for the
	 * personal-channel fan-out (over-notifying only triggers a harmless refetch on the client).
	 *
	 * @param array<string, int> $oldExplicit
	 * @param array<string, int> $newExplicit
	 * @param array<string, int> $oldMarkers
	 * @param array<string, int> $newMarkers
	 * @return array<string, true>
	 */
	private static function collectAffectedSubjects(
		array $oldExplicit,
		array $newExplicit,
		array $oldMarkers,
		array $newMarkers
	): array
	{
		$affected = [];
		foreach (array_keys($oldExplicit + $newExplicit) as $subjectCode)
		{
			if (($oldExplicit[$subjectCode] ?? null) !== ($newExplicit[$subjectCode] ?? null))
			{
				$affected[$subjectCode] = true;
			}
		}
		foreach (array_keys($oldMarkers + $newMarkers) as $subjectCode)
		{
			if (($oldMarkers[$subjectCode] ?? null) !== ($newMarkers[$subjectCode] ?? null))
			{
				$affected[$subjectCode] = true;
			}
		}

		return $affected;
	}

	/**
	 * [EVENT-01] Publish the cascade pull after commit over two paths:
	 *   1. collection channel — reaches viewers already subscribed to NOTE_COLLECTION_{cid};
	 *   2. personal channel of every affected recipient — a subtree grantee has no collection access
	 *      (not subscribed to the collection tag) and would otherwise miss the change on first grant
	 *      and on revoke.
	 *
	 * @param int[] $affectedDocumentIds
	 * @param string[] $affectedSubjectCodes
	 */
	private static function dispatchAccessCascade(
		int $collectionId,
		array $affectedDocumentIds,
		array $affectedSubjectCodes,
		?PushNotificationService $pushService
	): void
	{
		if (!Configuration::isAccessCascadeBroadcastEnabled())
		{
			return;
		}

		$push = $pushService ?? new PushNotificationService();

		// Path 1: collection cascade (threshold-aware payload handled inside emitDocumentCascade).
		$push->emitDocumentCascade($collectionId, $affectedDocumentIds, self::COMMAND_ACCESS_CASCADE);

		// Path 2: personal channel per affected recipient.
		$userIds = self::resolveSubjectUserIds($affectedSubjectCodes);
		if (empty($userIds))
		{
			return;
		}

		$requestRefetch = count($affectedDocumentIds) > PushNotificationService::REALTIME_BATCH_THRESHOLD;
		$personalPayload = $requestRefetch
			? ['collectionId' => $collectionId, 'requestRefetch' => true]
			: ['collectionId' => $collectionId, 'documentIds' => array_values(array_map('intval', $affectedDocumentIds))];

		$push->dispatchAfterCommit(static function () use ($push, $userIds, $personalPayload): void {
			$push->sendToUserChannels($userIds, self::COMMAND_ACCESS_CASCADE, $personalPayload);
		});
	}

	/**
	 * [P4.T6] Cascade pull for rows the background reconciler materialised. A widen past the sync
	 * threshold has no request behind it, so without this the grantee keeps seeing a partial subtree
	 * until a full reload: the rows arrive, but nobody tells the open client. Emitted per agent tick,
	 * not per chunk, so a huge source converges visibly without a push storm.
	 *
	 * @param int[] $documentIds
	 * @param string[] $subjectCodes
	 */
	public static function notifyMaterialisedSubtree(
		int $collectionId,
		array $documentIds,
		array $subjectCodes,
		?PushNotificationService $pushService = null
	): void
	{
		if ($collectionId <= 0 || empty($documentIds) || empty($subjectCodes))
		{
			return;
		}

		self::dispatchAccessCascade($collectionId, $documentIds, $subjectCodes, $pushService);
	}

	/**
	 * Reverse-resolves subject access codes to concrete user ids for the personal push path.
	 * Direct `U{id}` codes resolve locally; group/department/other provider codes are expanded to
	 * their members through the materialised user-access relations table (b_user_access). There is
	 * no ready-made reverse helper in the module — buildUserAccessCodes only goes user → codes.
	 *
	 * @param string[] $subjectCodes
	 * @return int[] distinct user ids (bounded by MAX_PERSONAL_RECIPIENTS)
	 */
	private static function resolveSubjectUserIds(array $subjectCodes): array
	{
		$codes = array_values(array_unique(array_filter(
			$subjectCodes,
			static fn($code) => is_string($code) && $code !== '' && $code !== '*',
		)));
		if (empty($codes))
		{
			return [];
		}

		$userIds = [];
		$lookupCodes = [];
		foreach ($codes as $code)
		{
			if (preg_match('/^U(\d+)$/', $code, $matches) === 1)
			{
				$uid = (int)$matches[1];
				if ($uid <= 0)
				{
					continue;
				}

				$userIds[$uid] = true;
				if (count($userIds) >= self::MAX_PERSONAL_RECIPIENTS)
				{
					return array_keys($userIds);
				}
			}
			else
			{
				$lookupCodes[] = $code;
			}
		}

		foreach (array_chunk($lookupCodes, 500) as $chunk)
		{
			$remaining = self::MAX_PERSONAL_RECIPIENTS - count($userIds);
			if ($remaining <= 0)
			{
				break;
			}

			// The cap has to bound the QUERY, not the loop over its result: a single department code
			// covers the whole portal, and fetchAll() would materialise all of it before the cap
			// discarded the tail. LIMIT counts rows, not new users, so a chunk that mostly repeats
			// already-seen users can stop below the cap — acceptable, since being at the cap already
			// means the audience is truncated.
			$rows = UserAccessTable::query()
				->setSelect(['USER_ID'])
				->whereIn('ACCESS_CODE', $chunk)
				->setDistinct()
				->setLimit($remaining)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$uid = (int)$row['USER_ID'];
				if ($uid > 0)
				{
					$userIds[$uid] = true;
				}
			}
		}

		return array_keys($userIds);
	}

	/**
	 * Users whose EFFECTIVE document-level access is above none on at least one of the given
	 * documents. This is the audience of the "Shared with me" tree: they see these documents
	 * without collection access, so tree changes must reach them through their personal pull
	 * channel (the collection channel is closed to them, and subscribing them to it would leak
	 * titles of documents they may not see).
	 *
	 * "Effective" is what {@see reduceDocumentLevel} computes, and it is NOT the same as "holds a
	 * positive row": an explicit deny (LEVEL_NONE, SOURCE_NONE) zeroes the WHOLE explicit
	 * contribution of whoever it covers, so a user reached by a group grant and denied personally
	 * has no access at all — while the group's row stays positive and would pull them into the
	 * audience together with the document title (documentCreate/documentUpdate/documentRestore
	 * carry it). Inheritance is hard and a deny cannot touch it, so a derived row always keeps its
	 * holder in, denied or not.
	 *
	 * @param int[] $documentIds
	 * @return int[] distinct user ids
	 */
	public static function resolveGranteeUserIds(array $documentIds): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($ids))
		{
			return [];
		}

		// Codes that grant unconditionally — every derived row, plus (below) the explicit rows of
		// documents carrying no deny at all. That is the overwhelmingly common case and it stays one
		// bulk expansion, exactly as before.
		$plainCodes = [];
		/** @var array<int, array<string, true>> $explicitByDocument */
		$explicitByDocument = [];
		/** @var array<int, array<string, true>> $denyByDocument */
		$denyByDocument = [];

		foreach (array_chunk($ids, 500) as $chunk)
		{
			$rows = DocumentAccessTable::query()
				->setSelect(['DOCUMENT_ID', 'SUBJECT_CODE', 'LEVEL', 'SOURCE_DOCUMENT_ID'])
				->whereIn('DOCUMENT_ID', $chunk)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$code = (string)($row['SUBJECT_CODE'] ?? '');
				if ($code === '')
				{
					continue;
				}

				$level = (int)$row['LEVEL'];
				if ((int)$row['SOURCE_DOCUMENT_ID'] !== self::SOURCE_NONE)
				{
					if ($level > self::LEVEL_NONE)
					{
						$plainCodes[$code] = true;
					}

					continue;
				}

				$documentId = (int)$row['DOCUMENT_ID'];
				if ($level > self::LEVEL_NONE)
				{
					$explicitByDocument[$documentId][$code] = true;
				}
				else
				{
					$denyByDocument[$documentId][$code] = true;
				}
			}
		}

		foreach ($explicitByDocument as $documentId => $codes)
		{
			if (!isset($denyByDocument[$documentId]))
			{
				$plainCodes += $codes;
				unset($explicitByDocument[$documentId]);
			}
		}

		$userIds = array_fill_keys(self::resolveSubjectUserIds(array_keys($plainCodes)), true);
		if (empty($explicitByDocument))
		{
			return array_keys($userIds);
		}

		// Only documents that actually carry a deny get here, so the per-document set arithmetic runs
		// over a handful of them. Each code is expanded once and reused across those documents; the
		// memory this holds is of the same order as the audience it produces.
		// Deny codes go in FIRST: the expansion is row-bounded, and truncating it must never make a
		// denied user look allowed. Dropping the tail of a positive code only costs that user a live
		// update (their client catches up on the next load); dropping a deny would push to someone the
		// document is explicitly closed to.
		$codesToExpand = [];
		foreach ($denyByDocument as $documentId => $codes)
		{
			if (isset($explicitByDocument[$documentId]))
			{
				$codesToExpand += $codes;
			}
		}
		foreach ($explicitByDocument as $codes)
		{
			$codesToExpand += $codes;
		}
		$usersByCode = self::expandCodesToUsers(array_keys($codesToExpand));

		foreach ($explicitByDocument as $documentId => $codes)
		{
			$denied = [];
			foreach (array_keys($denyByDocument[$documentId]) as $code)
			{
				$denied += $usersByCode[$code] ?? [];
			}

			foreach (array_keys($codes) as $code)
			{
				foreach (array_keys($usersByCode[$code] ?? []) as $userId)
				{
					if (!isset($denied[$userId]))
					{
						$userIds[$userId] = true;
					}
				}
			}
		}

		if (count($userIds) > self::MAX_PERSONAL_RECIPIENTS)
		{
			$userIds = array_slice($userIds, 0, self::MAX_PERSONAL_RECIPIENTS, true);
		}

		return array_keys($userIds);
	}

	/**
	 * Expands access codes to their members, KEEPING the code → users mapping — unlike
	 * {@see resolveSubjectUserIds}, which only needs the union and can merge the codes before
	 * querying. Needed where a user's membership has to be weighed against a deny on the same
	 * document, which the merged form can no longer tell apart.
	 *
	 * @param string[] $codes
	 * @return array<string, array<int, true>>
	 */
	private static function expandCodesToUsers(array $codes): array
	{
		$byCode = [];
		$lookupCodes = [];
		foreach ($codes as $code)
		{
			if (preg_match('/^U(\d+)$/', $code, $matches) === 1)
			{
				$userId = (int)$matches[1];
				$byCode[$code] = $userId > 0 ? [$userId => true] : [];
			}
			else
			{
				$byCode[$code] = [];
				$lookupCodes[] = $code;
			}
		}

		$budget = self::MAX_CODE_EXPANSION_ROWS;
		foreach (array_chunk($lookupCodes, 500) as $chunk)
		{
			if ($budget <= 0)
			{
				break;
			}

			$rows = UserAccessTable::query()
				->setSelect(['ACCESS_CODE', 'USER_ID'])
				->whereIn('ACCESS_CODE', $chunk)
				->setLimit($budget)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$budget--;
				$userId = (int)$row['USER_ID'];
				if ($userId > 0)
				{
					$byCode[(string)$row['ACCESS_CODE']][$userId] = true;
				}
			}
		}

		return $byCode;
	}

	private static function hasAnyAclRow(int $documentId): bool
	{
		if ($documentId <= 0)
		{
			return false;
		}

		$row = DocumentAccessTable::query()
			->setSelect(['ID'])
			->where('DOCUMENT_ID', $documentId)
			->setLimit(1)
			->exec()
			->fetch()
		;

		return $row !== false;
	}

	private static function dispatchDocumentCapabilities(int $documentId, ?PushNotificationService $pushService): void
	{
		if (!Configuration::isAccessCascadeBroadcastEnabled())
		{
			return;
		}

		$push = $pushService ?? new PushNotificationService();
		$push->dispatchAfterCommit(static function () use ($push, $documentId): void {
			$push->sendByTag(
				'NOTE_DOC_' . $documentId . '_ACL',
				'documentCapabilities',
				['documentId' => $documentId],
			);
		});
	}

	/**
	 * [API-02] One entry per ACL row (excluding the legacy '*' policy row). Scope/source read
	 * straight from SOURCE_DOCUMENT_ID — there is no separate scope store:
	 *   - source = SOURCE_NONE   → explicit grant on this document (scope 'document', mutable).
	 *   - source = $documentId   → subtree-scope marker on this document itself (scope 'subtree',
	 *                              mutable — this is where the grant originates).
	 *   - source = ancestor id   → derived inherited row (inherited=true, sourceDocumentId set,
	 *                              immutable — the frontend renders it read-only).
	 *
	 * @return array<int, array{subjectCode: string, level: string, scope: string, inherited: bool, sourceDocumentId: int|null}>
	 */
	public static function getDocumentPermissions(int $documentId): array
	{
		if ($documentId <= 0)
		{
			return [];
		}

		$accessItems = DocumentAccessTable::getList([
			'select' => ['SUBJECT_CODE', 'LEVEL', 'SOURCE_DOCUMENT_ID'],
			'filter' => [
				'=DOCUMENT_ID' => $documentId,
				'!=SUBJECT_CODE' => '*',
			],
			'order' => ['SUBJECT_CODE' => 'ASC', 'SOURCE_DOCUMENT_ID' => 'ASC'],
		])->fetchCollection();

		$rows = $accessItems->getAll();

		// An inherited grant cannot be edited here — only on its source document. Naming that source
		// is what makes the read-only tag actionable: without it a moderator sees a person they cannot
		// remove and no hint where to go. Safe to expose: derived rows are only ever materialised
		// within one collection, and reading permissions already requires MODERATE on it.
		$sourceIds = [];
		foreach ($rows as $item)
		{
			$source = (int)$item->getSourceDocumentId();
			if ($source !== self::SOURCE_NONE && $source !== $documentId)
			{
				$sourceIds[$source] = true;
			}
		}
		$sourceTitles = self::resolveDocumentTitles(array_keys($sourceIds));

		return array_map(static function ($item) use ($documentId, $sourceTitles): array {
			$source = (int)$item->getSourceDocumentId();
			$inherited = $source !== self::SOURCE_NONE && $source !== $documentId;

			return [
				'subjectCode' => (string)$item->getSubjectCode(),
				'level' => self::levelToCode((int)$item->getLevel()),
				'scope' => $source === $documentId ? self::SCOPE_SUBTREE : self::SCOPE_DOCUMENT,
				'inherited' => $inherited,
				'sourceDocumentId' => $inherited ? $source : null,
				'sourceDocumentTitle' => $inherited ? ($sourceTitles[$source] ?? '') : null,
			];
		}, $rows);
	}

	/**
	 * @param int[] $documentIds
	 * @return array<int, string> documentId => title
	 */
	private static function resolveDocumentTitles(array $documentIds): array
	{
		if (empty($documentIds))
		{
			return [];
		}

		$titles = [];
		foreach (array_chunk($documentIds, 500) as $chunk)
		{
			$rows = DocumentTable::query()
				->setSelect(['ID', 'TITLE'])
				->whereIn('ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$titles[(int)$row['ID']] = (string)($row['TITLE'] ?? '');
			}
		}

		return $titles;
	}

	/**
	 * @param array<int, array{id: int, collectionId: int}> $documents
	 * @return array<int, int> documentId => effective level
	 */
	public static function batchGetEffectiveLevels(array $documents, array $accessCodes, ?int $userId): array
	{
		if (empty($documents))
		{
			return [];
		}

		$userId = (int)$userId;
		$documentIds = [];
		$collectionIds = [];
		foreach ($documents as $doc)
		{
			$docId = (int)($doc['id'] ?? 0);
			$colId = (int)($doc['collectionId'] ?? 0);
			if ($docId <= 0)
			{
				continue;
			}
			$documentIds[] = $docId;
			if ($colId > 0)
			{
				$collectionIds[$colId] = true;
			}
		}

		if (empty($documentIds))
		{
			return [];
		}

		// Portal admins have full access to every document; grant the top level so any
		// requiredLevel check (VIEW..MODERATE) passes. This is the only caller path that
		// does not short-circuit admin before calling — the bulk aggregator relies on it;
		// every other caller (EntitySelector, mention/notification resolvers) already
		// returns early for admins and never reaches this branch.
		if ($userId > 0 && self::isPortalAdmin($userId))
		{
			return array_fill_keys($documentIds, CollectionAccessService::LEVEL_MODERATE);
		}

		$codes = array_values(array_unique(array_filter([
			...$accessCodes,
			$userId > 0 ? ('U' . $userId) : null,
		], static fn($code) => is_string($code) && $code !== '' && $code !== '*')));

		$documentLevels = [];
		if (!empty($codes))
		{
			$query = DocumentAccessTable::query()
				->setSelect(['DOCUMENT_ID', 'LEVEL', 'SOURCE_DOCUMENT_ID'])
				->whereIn('DOCUMENT_ID', $documentIds)
				->whereIn('SUBJECT_CODE', $codes)
				->exec()
			;

			$rowsByDoc = [];
			while ($row = $query->fetch())
			{
				$docId = (int)$row['DOCUMENT_ID'];
				$rowsByDoc[$docId][] = [
					'level' => (int)$row['LEVEL'],
					'source' => (int)$row['SOURCE_DOCUMENT_ID'],
				];
			}

			// Reuse the ALG-01 reducer so the batch path stays byte-for-byte identical to getDocumentLevel().
			foreach ($documentIds as $docId)
			{
				$documentLevels[$docId] = self::reduceDocumentLevel($rowsByDoc[$docId] ?? []);
			}
		}

		$collectionLevels = [];
		if (!empty($collectionIds) && !empty($accessCodes))
		{
			$collectionLevels = CollectionAccessService::batchGetUserLevels(
				array_keys($collectionIds),
				array_values(array_unique([...$accessCodes, '*'])),
			);
		}

		$result = [];
		foreach ($documents as $doc)
		{
			$docId = (int)($doc['id'] ?? 0);
			$colId = (int)($doc['collectionId'] ?? 0);
			if ($docId <= 0)
			{
				continue;
			}
			$docLevel = $documentLevels[$docId] ?? self::LEVEL_NONE;
			$colLevel = $collectionLevels[$colId] ?? self::LEVEL_NONE;
			$result[$docId] = max($docLevel, $colLevel);
		}

		return $result;
	}

	public static function deleteByDocumentId(int $documentId): Result
	{
		$result = new Result();

		if ($documentId <= 0)
		{
			return $result;
		}

		DocumentAccessTable::deleteByFilter(['=DOCUMENT_ID' => $documentId]);
		self::$userHasAnyGrantCache = null;

		return $result;
	}

	/**
	 * @param int[] $documentIds
	 */
	public static function deleteByDocumentIds(array $documentIds): void
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return;
		}

		DocumentAccessTable::deleteByFilter(['=DOCUMENT_ID' => $normalized]);
		self::$userHasAnyGrantCache = null;
	}

	public static function userHasAnyGrant(array $accessCodes): bool
	{
		$codes = array_values(array_unique(array_filter(
			$accessCodes,
			static fn($code) => is_string($code) && $code !== '' && $code !== '*',
		)));

		if (empty($codes))
		{
			return false;
		}

		$cacheKey = implode('|', $codes);
		if (isset(self::$userHasAnyGrantCache[$cacheKey]))
		{
			return self::$userHasAnyGrantCache[$cacheKey];
		}

		$row = DocumentAccessTable::query()
			->setSelect(['ID'])
			->whereIn('SUBJECT_CODE', $codes)
			->where('LEVEL', '>=', self::LEVEL_VIEW)
			->setLimit(1)
			->fetch()
		;

		$has = $row !== false;

		if (self::$userHasAnyGrantCache === null)
		{
			self::$userHasAnyGrantCache = [];
		}
		self::$userHasAnyGrantCache[$cacheKey] = $has;

		return $has;
	}

	public static function clearRequestCache(): void
	{
		self::$userHasAnyGrantCache = null;
	}

	/**
	 * Returns ids of documents accessible to the user purely via document-level grants
	 * (no collection-level VIEW or higher), ordered by ID DESC for cursor pagination.
	 *
	 * @param array<int, string> $accessCodes
	 * @param array{id: int}|null $afterCursor
	 * @return array{ids: int[], nextCursor: array{id: int}|null}
	 */
	public static function listSharedWithMeIds(array $accessCodes, int $limit, ?array $afterCursor = null): array
	{
		// A portal administrator sees every collection in full, so the "shared with me"
		// section (document-level grants only) is empty for them.
		if (PortalAdmin::isCurrentUserAdmin())
		{
			return ['ids' => [], 'nextCursor' => null];
		}

		$limit = max(1, min(200, $limit));

		$codesPersonal = array_values(array_unique(array_filter(
			$accessCodes,
			static fn($code) => is_string($code) && $code !== '' && $code !== '*',
		)));

		if (empty($codesPersonal))
		{
			return ['ids' => [], 'nextCursor' => null];
		}

		$collectionLevels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];
		$accessibleCollectionIds = [];
		foreach ($collectionLevels as $cid => $level)
		{
			if ((int)$level >= CollectionAccessService::LEVEL_VIEW)
			{
				$accessibleCollectionIds[] = (int)$cid;
			}
		}

		$query = DocumentTable::query()
			->setSelect(['ID'])
			->where('IS_ARCHIVED', 'N')
			// Trashed documents belong to the recycle bin locus, not to this listing — the same
			// exclusion the archive listing and the accessible tree apply.
			->whereNull('RECYCLE_BIN.ID')
			->where(self::buildDocumentGrantFilter('ID', $codesPersonal))
			->addOrder('ID', 'DESC')
			->setLimit($limit + 1)
		;

		if (!empty($accessibleCollectionIds))
		{
			$query->whereNotIn('COLLECTION_ID', $accessibleCollectionIds);
		}

		$afterId = isset($afterCursor['id']) ? (int)$afterCursor['id'] : 0;
		if ($afterId > 0)
		{
			$query->where('ID', '<', $afterId);
		}

		$ids = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		$nextCursor = null;
		if (count($ids) > $limit)
		{
			$ids = array_slice($ids, 0, $limit);
			$nextCursor = ['id' => end($ids)];
		}

		return ['ids' => $ids, 'nextCursor' => $nextCursor];
	}

	/**
	 * [ALG-02] Visibility predicate for a document reached through document-level grants, as an ORM
	 * condition tree — the single source of truth for every query that asks it (/shared/ listing,
	 * archive listing, search filter). Keeping one builder is not cosmetic: a second copy could drift
	 * and make one surface show what another hides.
	 *
	 *   inherited positive (source != 0, VIEW+)  OR  (explicit positive (source = 0, VIEW+) AND NOT explicit deny (source = 0, NONE))
	 *
	 * The OR-structure is load-bearing, not a conjunction: an inherited positive grant must win even
	 * when a local explicit none exists (inheritance is hard per ADR), so the deny sub-query
	 * constrains ONLY the explicit branch. Agrees with {@see reduceDocumentLevel()} on the
	 * "at least VIEW?" question, which is all a listing needs.
	 *
	 * @param string $documentField document-id field of the OUTER query ('ID' on documents,
	 *                              'DOCUMENT_ID' on the search index)
	 * @param string[] $codesPersonal access codes without '*' (a '*' row is a collection policy,
	 *                                never a document grant)
	 */
	public static function buildDocumentGrantFilter(string $documentField, array $codesPersonal): ConditionTree
	{
		return Query::filter()->logic('or')
			->whereIn(
				$documentField,
				DocumentAccessTable::query()
					->setSelect(['DOCUMENT_ID'])
					->whereIn('SUBJECT_CODE', $codesPersonal)
					->where('SOURCE_DOCUMENT_ID', '!=', self::SOURCE_NONE)
					->where('LEVEL', '>=', self::LEVEL_VIEW),
			)
			->where(
				Query::filter()
					->whereIn(
						$documentField,
						DocumentAccessTable::query()
							->setSelect(['DOCUMENT_ID'])
							->whereIn('SUBJECT_CODE', $codesPersonal)
							->where('SOURCE_DOCUMENT_ID', self::SOURCE_NONE)
							->where('LEVEL', '>=', self::LEVEL_VIEW),
					)
					->whereNotIn(
						$documentField,
						DocumentAccessTable::query()
							->setSelect(['DOCUMENT_ID'])
							->whereIn('SUBJECT_CODE', $codesPersonal)
							->where('SOURCE_DOCUMENT_ID', self::SOURCE_NONE)
							->where('LEVEL', self::LEVEL_NONE),
					),
			)
		;
	}

	/**
	 * [ALG-05] Full visibility predicate of a LISTING: the document-grant core above OR the
	 * collection-VIEW branch. This is what list queries put in their WHERE instead of fetching a
	 * window and dropping rows in PHP afterwards; the caller supplies the two field names of its
	 * own query, so the same builder serves backlinks (SOURCE_ID / SOURCE.COLLECTION_ID) and
	 * favorites (DOCUMENT.ID / DOCUMENT.COLLECTION_ID over a joined document).
	 *
	 * Collection visibility stays a flat id list resolved in PHP by
	 * {@see CollectionAccessService::getAllUserLevels()} — it already folds the '*' policy and the
	 * max(personal, policy) rule, and re-expressing that in SQL would be the second copy this
	 * builder exists to prevent.
	 *
	 * FAIL-CLOSED, and not incidentally: ConditionTree::whereIn() SKIPS the condition entirely when
	 * given an empty array, so calling the core with no personal codes would silently drop the
	 * SUBJECT_CODE restriction inside its sub-queries and turn the predicate into "anything anyone
	 * was ever granted". Both branches are therefore added only when they have input, and a
	 * predicate with no branches at all must be false rather than empty — an empty ConditionTree
	 * means "no conditions", i.e. everything. The PHP path this replaces behaves the same way
	 * ({@see batchGetEffectiveLevels()} yields no levels without codes).
	 *
	 * Portal admins are NOT handled here: the caller skips the predicate for them, because what an
	 * admin may see is a per-surface decision (backlinks and favorites show everything, /shared/
	 * shows nothing).
	 *
	 * @param string $documentField document-id field of the OUTER query
	 * @param string $collectionField collection-id field of the OUTER query
	 * @param string[] $codesPersonal access codes without '*'
	 * @param int[] $accessibleCollectionIds collections the user holds VIEW+ on
	 */
	public static function buildListVisibilityFilter(
		string $documentField,
		string $collectionField,
		array $codesPersonal,
		array $accessibleCollectionIds,
	): ConditionTree
	{
		$filter = Query::filter()->logic('or');
		$hasBranch = false;

		if (!empty($codesPersonal))
		{
			$filter->where(self::buildDocumentGrantFilter($documentField, $codesPersonal));
			$hasBranch = true;
		}

		if (!empty($accessibleCollectionIds))
		{
			$filter->whereIn($collectionField, $accessibleCollectionIds);
			$hasBranch = true;
		}

		if (!$hasBranch)
		{
			// Deliberately unsatisfiable: ids are always positive, so this yields an empty page for
			// a user with neither codes nor collections. NULL (a LEFT JOIN miss) compares false too.
			return Query::filter()->where($documentField, '<', 0);
		}

		return $filter;
	}

	/**
	 * Access codes minus '*': a '*' row is a collection policy, never a document grant. Feeds the
	 * $codesPersonal argument above.
	 *
	 * @param array<int, string> $accessCodes
	 * @return array<int, string>
	 */
	public static function personalCodes(array $accessCodes): array
	{
		return array_values(array_filter(
			$accessCodes,
			static fn($code): bool => is_string($code) && $code !== '' && $code !== '*',
		));
	}

	/**
	 * Collections of the given level map the user may read. Feeds the $accessibleCollectionIds
	 * argument above.
	 *
	 * Lives next to the predicate rather than in each caller: the VIEW threshold is what decides
	 * whether a whole knowledge base shows up in a list, and two copies of it would eventually be
	 * raised in one place only.
	 *
	 * @param array<int|string, int|string> $collectionLevels collectionId => effective level, as
	 *        {@see CollectionAccessService::getAllUserLevels()} returns under 'effective'
	 * @return int[]
	 */
	public static function accessibleCollectionIds(array $collectionLevels): array
	{
		$ids = [];
		foreach ($collectionLevels as $collectionId => $level)
		{
			if ((int)$level >= CollectionAccessService::LEVEL_VIEW)
			{
				$ids[] = (int)$collectionId;
			}
		}

		return $ids;
	}

	/**
	 * Returns ids of archived documents accessible to the user via either collection-VIEW
	 * or a document-level grant; respects NONE-overrides on the document.
	 * Ordered by (ARCHIVED_AT DESC, ID DESC) using a composite cursor.
	 *
	 * @param array<int, string> $accessCodes
	 * @param array{archivedAt: string, id: int}|null $afterCursor
	 * @return array{ids: int[], nextCursor: array{archivedAt: string, id: int}|null}
	 */
	public static function listArchivedIdsForUser(array $accessCodes, int $limit, ?array $afterCursor = null): array
	{
		$limit = max(1, min(200, $limit));

		$isAdmin = (bool)PortalAdmin::isCurrentUserAdmin();

		$codesPersonal = array_values(array_unique(array_filter(
			$accessCodes,
			static fn($code) => is_string($code) && $code !== '' && $code !== '*',
		)));

		$accessibleCollectionIds = [];
		if (!$isAdmin)
		{
			$collectionLevels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];
			foreach ($collectionLevels as $cid => $level)
			{
				if ((int)$level >= CollectionAccessService::LEVEL_VIEW)
				{
					$accessibleCollectionIds[] = (int)$cid;
				}
			}

			if (empty($codesPersonal) && empty($accessibleCollectionIds))
			{
				return ['ids' => [], 'nextCursor' => null];
			}
		}

		$query = DocumentTable::query()
			->setSelect(['ID', 'ARCHIVED_AT'])
			->where('IS_ARCHIVED', 'Y')
			->whereNotNull('ARCHIVED_AT')
			->whereNull('RECYCLE_BIN.ID')
		;

		if (!$isAdmin)
		{
			$accessFilter = Query::filter()->logic('or');
			if (!empty($accessibleCollectionIds))
			{
				$accessFilter->whereIn('COLLECTION_ID', $accessibleCollectionIds);
			}
			if (!empty($codesPersonal))
			{
				$accessFilter->where(self::buildDocumentGrantFilter('ID', $codesPersonal));
			}
			$query->where($accessFilter);
		}

		$cursorId = isset($afterCursor['id']) ? (int)$afterCursor['id'] : 0;
		$cursorAtRaw = is_string($afterCursor['archivedAt'] ?? null) ? $afterCursor['archivedAt'] : '';
		if ($cursorId > 0 && $cursorAtRaw !== '')
		{
			$cursorAt = DateTime::createFromPhp(new \DateTime($cursorAtRaw));
			$query->where(Query::filter()->logic('or')
				->where('ARCHIVED_AT', '<', $cursorAt)
				->where(Query::filter()
					->where('ARCHIVED_AT', $cursorAt)
					->where('ID', '<', $cursorId)
				)
			);
		}

		$query
			->addOrder('ARCHIVED_AT', 'DESC')
			->addOrder('ID', 'DESC')
			->setLimit($limit + 1)
		;

		$rows = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$archivedAt = $row['ARCHIVED_AT'];
			if ($archivedAt instanceof DateTime)
			{
				$archivedAtSql = $archivedAt->format('Y-m-d H:i:s');
			}
			elseif ($archivedAt !== null && $archivedAt !== '')
			{
				$archivedAtSql = (string)$archivedAt;
			}
			else
			{
				$archivedAtSql = '';
			}
			$rows[] = [
				'id' => (int)$row['ID'],
				'archivedAt' => $archivedAtSql,
			];
		}

		$nextCursor = null;
		if (count($rows) > $limit)
		{
			$rows = array_slice($rows, 0, $limit);
			$last = end($rows);
			$nextCursor = ['archivedAt' => $last['archivedAt'], 'id' => $last['id']];
		}

		$ids = array_map(static fn(array $row): int => $row['id'], $rows);

		return ['ids' => $ids, 'nextCursor' => $nextCursor];
	}

	/**
	 * Returns all archived document ids whose collection grants the user MANAGE-level.
	 * No pagination — used by RestoreAllArchivedDocumentsCommand only.
	 *
	 * @return int[]
	 */
	public static function listArchivedIdsForUserWithManageAccess(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		if (PortalAdmin::isAdmin($userId))
		{
			$rows = DocumentTable::query()
				->setSelect(['ID'])
				->where('IS_ARCHIVED', 'Y')
				->whereNull('RECYCLE_BIN.ID')
				->fetchAll()
			;

			return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);

		$collectionLevels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];
		$manageCollectionIds = [];
		foreach ($collectionLevels as $cid => $level)
		{
			if ((int)$level >= CollectionAccessService::LEVEL_MANAGE)
			{
				$manageCollectionIds[] = (int)$cid;
			}
		}

		if (empty($manageCollectionIds))
		{
			return [];
		}

		$rows = DocumentTable::query()
			->setSelect(['ID'])
			->where('IS_ARCHIVED', 'Y')
			->whereIn('COLLECTION_ID', $manageCollectionIds)
			->whereNull('RECYCLE_BIN.ID')
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	public static function isPortalAdmin(int $userId): bool
	{
		return PortalAdmin::isAdmin($userId);
	}

	/**
	 * Returns recycleBinIds visible to the user, paginated by composite cursor (TRASHED_AT DESC, ID DESC).
	 * A row is visible when the user is admin, or:
	 *   - DOCUMENT.COLLECTION_ID is in the user's accessible-collection list (VIEW+) on a live collection, OR
	 *   - the source collection no longer exists AND user is TRASHED_BY or DOCUMENT.CREATED_BY (orphan).
	 *
	 * @param array<int, string> $accessCodes
	 * @param array{trashedAt: string, recycleBinId: int}|null $afterCursor
	 * @return array{ids: int[], nextCursor: array{trashedAt: string, recycleBinId: int}|null}
	 */
	public static function listRecycleBinRecordsForUser(int $userId, array $accessCodes, int $limit, ?array $afterCursor = null): array
	{
		if ($userId <= 0)
		{
			return ['ids' => [], 'nextCursor' => null];
		}

		$limit = max(1, min(200, $limit));
		$fetchLimit = $limit + 1;

		$isAdmin = self::isPortalAdmin($userId);

		$accessibleCollectionIds = [];
		if (!$isAdmin)
		{
			$collectionLevels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];
			foreach ($collectionLevels as $cid => $level)
			{
				if ((int)$level >= CollectionAccessService::LEVEL_VIEW)
				{
					$accessibleCollectionIds[] = (int)$cid;
				}
			}
		}

		$rows = (new RecycleBinRepository())->listVisible(
			$isAdmin ? null : $userId,
			$accessibleCollectionIds,
			$afterCursor,
			$fetchLimit,
		);

		$nextCursor = null;
		if (count($rows) > $limit)
		{
			$rows = array_slice($rows, 0, $limit);
			$last = end($rows);
			$nextCursor = ['trashedAt' => $last['trashedAt'], 'recycleBinId' => $last['id']];
		}

		$ids = array_map(static fn(array $row): int => $row['id'], $rows);

		return ['ids' => $ids, 'nextCursor' => $nextCursor];
	}

	/**
	 * Visibility gate for trashed documents (controller getAction, mapping).
	 * Aligned with listVisible:
	 *   - admin → true
	 *   - source collection alive → user must have VIEW+ on it (authorship/trashedBy alone
	 *     is not enough; revoking VIEW must hide the trashed document)
	 *   - source collection gone (orphan) → trashedBy or document.createdBy may see it.
	 */
	public static function canViewInRecycleBin(int $userId, RecycleBinRecord $record): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (self::isPortalAdmin($userId))
		{
			return true;
		}

		$meta = self::resolveDocumentMeta($record->getDocumentId());
		if ($meta === null)
		{
			return false;
		}

		$collectionId = $meta['collectionId'];
		$collectionAlive = $collectionId > 0 && self::collectionExists($collectionId);

		if (!$collectionAlive)
		{
			return $record->getTrashedBy() === $userId || $meta['createdBy'] === $userId;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];

		return ((int)($levels[$collectionId] ?? 0)) >= CollectionAccessService::LEVEL_VIEW;
	}

	public static function canRestoreFromRecycleBin(int $userId, RecycleBinRecord $record): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (self::isPortalAdmin($userId))
		{
			return true;
		}

		$meta = self::resolveDocumentMeta($record->getDocumentId());
		if ($meta === null)
		{
			return false;
		}

		$collectionId = $meta['collectionId'];
		if ($collectionId <= 0 || !self::collectionExists($collectionId))
		{
			// orphan: only the user who trashed it or the document author may restore
			// (target collection is picked separately and re-validated for MANAGE)
			return $record->getTrashedBy() === $userId || $meta['createdBy'] === $userId;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];

		return ((int)($levels[$collectionId] ?? 0)) >= CollectionAccessService::LEVEL_MANAGE;
	}

	public static function canHardDeleteFromRecycleBin(int $userId, RecycleBinRecord $record): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (self::isPortalAdmin($userId))
		{
			return true;
		}

		$meta = self::resolveDocumentMeta($record->getDocumentId());
		if ($meta === null)
		{
			return false;
		}

		$collectionId = $meta['collectionId'];
		if ($collectionId <= 0 || !self::collectionExists($collectionId))
		{
			return $record->getTrashedBy() === $userId;
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];

		return ((int)($levels[$collectionId] ?? 0)) >= CollectionAccessService::LEVEL_MANAGE;
	}

	/**
	 * Batch ACL computation for recycle-bin listings — replaces N×(canView/canRestore/canHardDelete)
	 * point queries with: 1 SELECT documents + 1 SELECT collections + 1 user-levels rebuild.
	 *
	 * @param RecycleBinRecord[] $records
	 * @return array<int, array{canView: bool, canRestore: bool, canHardDelete: bool}>
	 *         keyed by recycle-bin record ID. Missing keys mean no access.
	 */
	public static function bulkComputeRecycleBinAcl(int $userId, array $records): array
	{
		if ($userId <= 0 || empty($records))
		{
			return [];
		}

		if (self::isPortalAdmin($userId))
		{
			$out = [];
			foreach ($records as $record)
			{
				$out[(int)$record->getId()] = ['canView' => true, 'canRestore' => true, 'canHardDelete' => true];
			}

			return $out;
		}

		$documentIds = [];
		foreach ($records as $record)
		{
			$documentIds[(int)$record->getDocumentId()] = true;
		}

		$metaById = [];
		if (!empty($documentIds))
		{
			$rows = DocumentTable::query()
				->setSelect(['ID', 'COLLECTION_ID', 'CREATED_BY'])
				->whereIn('ID', array_keys($documentIds))
				->exec();
			while ($row = $rows->fetch())
			{
				$metaById[(int)$row['ID']] = [
					'collectionId' => (int)($row['COLLECTION_ID'] ?? 0),
					'createdBy' => (int)($row['CREATED_BY'] ?? 0),
				];
			}
		}

		$collectionIds = [];
		foreach ($metaById as $meta)
		{
			if ($meta['collectionId'] > 0)
			{
				$collectionIds[$meta['collectionId']] = true;
			}
		}

		$aliveCollections = [];
		if (!empty($collectionIds))
		{
			$rows = CollectionTable::query()
				->setSelect(['ID'])
				->whereIn('ID', array_keys($collectionIds))
				->exec();
			while ($row = $rows->fetch())
			{
				$aliveCollections[(int)$row['ID']] = true;
			}
		}

		$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
		$levels = CollectionAccessService::getAllUserLevels($accessCodes)['effective'] ?? [];

		$out = [];
		foreach ($records as $record)
		{
			$recordId = (int)$record->getId();
			$documentId = (int)$record->getDocumentId();
			$trashedBy = (int)$record->getTrashedBy();
			$meta = $metaById[$documentId] ?? null;

			if ($meta === null)
			{
				$out[$recordId] = ['canView' => false, 'canRestore' => false, 'canHardDelete' => false];

				continue;
			}

			$collectionId = $meta['collectionId'];
			$createdBy = $meta['createdBy'];
			$alive = $collectionId > 0 && isset($aliveCollections[$collectionId]);
			$level = $alive ? (int)($levels[$collectionId] ?? 0) : 0;

			$canView = $alive
				? $level >= CollectionAccessService::LEVEL_VIEW
				: ($trashedBy === $userId || $createdBy === $userId);

			$canRestore = $alive
				? $level >= CollectionAccessService::LEVEL_MANAGE
				: ($trashedBy === $userId || $createdBy === $userId);

			$canHardDelete = $alive
				? $level >= CollectionAccessService::LEVEL_MANAGE
				: $trashedBy === $userId;

			$out[$recordId] = [
				'canView' => $canView,
				'canRestore' => $canRestore,
				'canHardDelete' => $canHardDelete,
			];
		}

		return $out;
	}

	private static function resolveDocumentCollectionId(int $documentId): ?int
	{
		$meta = self::resolveDocumentMeta($documentId);

		return $meta === null ? null : $meta['collectionId'];
	}

	/**
	 * @return array{collectionId: int, createdBy: int}|null
	 */
	private static function resolveDocumentMeta(int $documentId): ?array
	{
		if ($documentId <= 0)
		{
			return null;
		}

		$row = DocumentTable::query()
			->setSelect(['COLLECTION_ID', 'CREATED_BY'])
			->where('ID', $documentId)
			->setLimit(1)
			->fetch();

		if ($row === false)
		{
			return null;
		}

		return [
			'collectionId' => (int)($row['COLLECTION_ID'] ?? 0),
			'createdBy' => (int)($row['CREATED_BY'] ?? 0),
		];
	}

	private static function collectionExists(int $collectionId): bool
	{
		if ($collectionId <= 0)
		{
			return false;
		}

		$row = \Bitrix\Note\Internal\Model\CollectionTable::query()
			->setSelect(['ID'])
			->where('ID', $collectionId)
			->setLimit(1)
			->fetch();

		return $row !== false;
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
}
