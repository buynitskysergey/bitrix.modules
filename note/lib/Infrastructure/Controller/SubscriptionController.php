<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Internal\Model\SubscriptionTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Subscription\CoverageResolver;
use Bitrix\Note\Public\Command\RemoveSubscriptionCommand;
use Bitrix\Note\Public\Command\SetSubscriptionCommand;
use Bitrix\Note\Public\Provider\SubscriptionProvider;

/**
 * [P6.T3 / API-07..09] Bell control: subscribe/change scope, unsubscribe, and
 * read state. No auto-subscription anywhere in the module — every row here is
 * created by an explicit user action.
 */
class SubscriptionController extends Controller
{
	use TargetViewAccessTrait;

	private const ALLOWED_SCOPES = [SubscriptionTable::SCOPE_DOCUMENT, SubscriptionTable::SCOPE_COLLECTION];
	// Same upper bound the branch listings normalise their page size to. A cap on one request, not on
	// what a caller may ask about: anything longer is the caller's to split (the sidebar mirrors this
	// number in STATES_BATCH_SIZE), because a branch is read whole on every page it grows by and a
	// silently trimmed tail would draw rows with no bell where a bell belongs.
	private const MAX_STATES_BATCH = 200;
	private const ALLOWED_MODES_BY_SCOPE = [
		SubscriptionTable::SCOPE_DOCUMENT => [SubscriptionTable::MODE_SELF, SubscriptionTable::MODE_SUBTREE, SubscriptionTable::MODE_MUTED],
		SubscriptionTable::SCOPE_COLLECTION => [SubscriptionTable::MODE_ALL],
	];

	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	/**
	 * [API-07] Subscribe / change scope. Idempotent upsert; requires LEVEL_VIEW on
	 * the target (you can only subscribe to something you can see).
	 */
	public function setAction(string $scope, int $entityId, string $mode): ?array
	{
		$scope = mb_strtolower(trim($scope));
		$mode = mb_strtolower(trim($mode));

		if (!$this->assertValidScopeAndMode($scope, $mode))
		{
			return null;
		}

		if (!$this->assertTargetViewAccess($scope, $entityId))
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new SetSubscriptionCommand($userId, $scope, $entityId, $mode))->run();
		}
		catch (CommandException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_SUBSCRIPTION_SET_ERROR')));

			return null;
		}

		// [API-05] The invariant refuses through Result data, not an exception: AbstractCommand::run()
		// wraps every exception of execute() into CommandException, so a typed catch cannot tell the
		// reasons apart.
		if (($result->getData()['errorCode'] ?? null) === SetSubscriptionCommand::ERROR_FAVORITE_REQUIRED)
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_SUBSCRIPTION_FAVORITE_REQUIRED'),
				SetSubscriptionCommand::ERROR_FAVORITE_REQUIRED,
			));

			return null;
		}

		return ['success' => true];
	}

	/**
	 * [API-08] Unsubscribe — removes the caller's own subscription. No
	 * target-access check: a user must be able to unsubscribe even after losing
	 * view rights on the target.
	 */
	public function removeAction(string $scope, int $entityId): ?array
	{
		$scope = mb_strtolower(trim($scope));

		if (!in_array($scope, self::ALLOWED_SCOPES, true))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_SUBSCRIPTION_INVALID_SCOPE'), 'INVALID_SCOPE'));

			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			(new RemoveSubscriptionCommand($userId, $scope, $entityId))->run();
		}
		catch (CommandException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_SUBSCRIPTION_REMOVE_ERROR')));

			return null;
		}

		return ['success' => true];
	}

	/**
	 * [API-09] State for the bell control. `collectionId` is normally the
	 * document's own collection and only needs passing when the caller wants to
	 * confirm it explicitly (e.g. a stale client-side breadcrumb).
	 */
	public function getStateAction(int $documentId, ?int $collectionId = null): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();

		try
		{
			return (new SubscriptionProvider())->getState($userId, $documentId, $collectionId);
		}
		catch (DocumentNotFoundException)
		{
			Context::getCurrent()->getResponse()->setStatus(404);

			return null;
		}
		catch (AccessDeniedException)
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->denyAccess();

			return null;
		}
	}

	/**
	 * [P4.T6 / API-06] Coverage states of a whole branch in one call. The tree endpoints carry no
	 * notification state (the main tree draws no bells), yet the nested rows of an expanded favorites
	 * row have to show coverage and mute - hence this batch read.
	 *
	 * The rule itself is not reimplemented here: it is one CoverageResolver call. Documents the user
	 * may not see, documents of another knowledge base and documents that no longer exist are simply
	 * absent from the map - their existence is never disclosed.
	 *
	 * @param int[] $documentIds
	 * @return array{states: array<int, array<string, mixed>>}|null
	 */
	public function getStatesAction(int $collectionId, array $documentIds): ?array
	{
		$empty = ['states' => []];

		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0 || $collectionId <= 0)
		{
			return $empty;
		}

		$normalizedIds = array_slice(
			array_values(array_unique(array_filter(
				array_map(static fn($id): int => (int)$id, $documentIds),
				static fn(int $id): bool => $id > 0,
			))),
			0,
			self::MAX_STATES_BATCH,
		);
		if (empty($normalizedIds))
		{
			return $empty;
		}

		// Scoping by the requested knowledge base also drops ids that no longer exist, and keeps the
		// access verdict below computed against the collection the documents really live in.
		$collectionIds = (new DocumentRepository())->getCollectionIds($normalizedIds);
		$scopedIds = array_values(array_filter(
			$normalizedIds,
			static fn(int $id): bool => ($collectionIds[$id] ?? 0) === $collectionId,
		));
		if (empty($scopedIds))
		{
			return $empty;
		}

		$levels = DocumentAccessService::batchGetEffectiveLevels(
			array_map(static fn(int $id): array => ['id' => $id, 'collectionId' => $collectionId], $scopedIds),
			CollectionAccessService::buildUserAccessCodes($userId),
			$userId,
		);

		$items = [];
		foreach ($scopedIds as $id)
		{
			if (($levels[$id] ?? DocumentAccessService::LEVEL_NONE) >= DocumentAccessService::LEVEL_VIEW)
			{
				$items[$id] = $collectionId;
			}
		}
		if (empty($items))
		{
			return $empty;
		}

		return ['states' => (new CoverageResolver())->resolve($userId, $items)];
	}

	private function assertValidScopeAndMode(string $scope, string $mode): bool
	{
		if (!in_array($scope, self::ALLOWED_SCOPES, true))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_SUBSCRIPTION_INVALID_SCOPE'), 'INVALID_SCOPE'));

			return false;
		}

		if (!in_array($mode, self::ALLOWED_MODES_BY_SCOPE[$scope], true))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_SUBSCRIPTION_INVALID_MODE'), 'INVALID_MODE'));

			return false;
		}

		return true;
	}

	// The check itself lives in TargetViewAccessTrait - shared with FavoriteController. Only the
	// subscription vocabulary of the scope is resolved here.
	private function assertTargetViewAccess(string $scope, int $entityId): bool
	{
		return $scope === SubscriptionTable::SCOPE_DOCUMENT
			? $this->assertTargetDocumentViewAccess($entityId)
			: $this->assertTargetCollectionViewAccess($entityId);
	}
}
