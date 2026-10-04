<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Model\FavoriteTable;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Favorite\FavoriteListService;
use Bitrix\Note\Internal\Service\RecycleBin\RecycleBinFilter;
use Bitrix\Note\Public\Command\AddFavoriteCommand;
use Bitrix\Note\Public\Command\MoveFavoriteCommand;
use Bitrix\Note\Public\Command\RemoveFavoriteCommand;
use Bitrix\Note\Public\Provider\FavoriteProvider;

/**
 * [P3.T4 / API-01..03] Personal favorites: composition and manual order of one user's list.
 *
 * Adding requires LEVEL_VIEW on the target - only something visible can be starred - plus the same
 * two exclusions the listing applies: neither a collection's main document nor a trashed document may
 * become a bookmark. Removing and reordering check nothing: cleaning up and reordering one's own list
 * must stay possible after access to the object is gone.
 */
class FavoriteController extends Controller
{
	use TargetViewAccessTrait;

	public const ERROR_MAIN_DOCUMENT_NOT_ALLOWED = 'MAIN_DOCUMENT_NOT_ALLOWED';
	public const ERROR_DOCUMENT_TRASHED = 'DOCUMENT_TRASHED';

	private const ALLOWED_ENTITY_TYPES = [
		FavoriteTable::ENTITY_TYPE_DOCUMENT,
		FavoriteTable::ENTITY_TYPE_COLLECTION,
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
	 * [API-04] One page of the caller's own list, ordered (POSITION DESC, ID DESC). The end of the
	 * list is `nextCursor === null` - never the number of returned rows, which the visibility and
	 * notification filters may leave short.
	 *
	 * `onlyNotified` arrives from an urlencoded request, where a plain false would travel as the
	 * string "false", so the flag is read tolerantly (1/0/"1"/"0"/true/false/"true"/"false").
	 *
	 * @param array{position: int, id: int}|null $afterCursor
	 */
	public function listAction(int $limit = FavoriteListService::DEFAULT_LIMIT, ?array $afterCursor = null, $onlyNotified = false): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			return null;
		}

		return (new FavoriteProvider())->getPage(
			$userId,
			$limit,
			$afterCursor,
			$this->normalizeFlag($onlyNotified),
		);
	}

	/**
	 * [API-01] Adds the target to the caller's favorites. `position` is the ordinal of the insertion
	 * point (1 is the topmost row); without it the row goes on top. Idempotent.
	 */
	public function addAction(string $entityType, int $entityId, ?int $position = null): ?array
	{
		$entityType = $this->normalizeEntityType($entityType);
		if ($entityType === null)
		{
			return null;
		}

		if (!$this->assertTargetViewAccess($entityType, $entityId))
		{
			return null;
		}

		if (
			$entityType === FavoriteTable::ENTITY_TYPE_DOCUMENT
			&& !$this->assertDocumentMayBeStarred($entityId)
		)
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new AddFavoriteCommand($userId, $entityType, $entityId, $position))->run();
		}
		catch (CommandException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FAVORITE_ADD_ERROR')));

			return null;
		}

		return $result->getData();
	}

	/**
	 * [API-02] Removes the target from the caller's favorites together with the positive subscription
	 * on it, and returns what was taken away so a caller can put it back.
	 */
	public function removeAction(string $entityType, int $entityId): ?array
	{
		$entityType = $this->normalizeEntityType($entityType);
		if ($entityType === null)
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new RemoveFavoriteCommand($userId, $entityType, $entityId))->run();
		}
		catch (CommandException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FAVORITE_REMOVE_ERROR')));

			return null;
		}

		return $result->getData();
	}

	/**
	 * [API-03] Reorders one row of the caller's list. `position` is the ordinal of the target
	 * insertion point.
	 */
	public function moveAction(string $entityType, int $entityId, ?int $position = null): ?array
	{
		$entityType = $this->normalizeEntityType($entityType);
		if ($entityType === null)
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();
		try
		{
			$result = (new MoveFavoriteCommand($userId, $entityType, $entityId, $position))->run();
		}
		catch (CommandException)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FAVORITE_MOVE_ERROR')));

			return null;
		}

		return $result->getData();
	}

	private function normalizeFlag(mixed $value): bool
	{
		if (is_string($value))
		{
			return in_array(mb_strtolower(trim($value)), ['1', 'true', 'y'], true);
		}

		return (bool)$value;
	}

	private function normalizeEntityType(string $entityType): ?string
	{
		$normalized = mb_strtolower(trim($entityType));
		if (!in_array($normalized, self::ALLOWED_ENTITY_TYPES, true))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FAVORITE_INVALID_ENTITY_TYPE'), 'INVALID_ENTITY_TYPE'));

			return null;
		}

		return $normalized;
	}

	// The check itself lives in TargetViewAccessTrait - shared with SubscriptionController. Only the
	// favorites vocabulary of the entity type is resolved here.
	private function assertTargetViewAccess(string $entityType, int $entityId): bool
	{
		return $entityType === FavoriteTable::ENTITY_TYPE_DOCUMENT
			? $this->assertTargetDocumentViewAccess($entityId)
			: $this->assertTargetCollectionViewAccess($entityId);
	}

	/**
	 * [API-01] Only a document a listing can display may be starred: FavoriteListService drops the
	 * collection's main document (the hidden carrier of the knowledge base description, edited in the
	 * "about" tab) and everything in the recycle bin, so a row on either would just hold a place in the
	 * personal list and carry a subscription. Refused with its own code - silence would look like
	 * success, and "not found" would be a lie about a document the user can open.
	 *
	 * Runs after the view check, so the state of an invisible document is never disclosed. Adding only:
	 * removing and reordering must stay possible whatever happened to the object.
	 */
	private function assertDocumentMayBeStarred(int $documentId): bool
	{
		if ((new DocumentRepository())->isMainDocument($documentId))
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_FAVORITE_MAIN_DOCUMENT_NOT_ALLOWED'),
				self::ERROR_MAIN_DOCUMENT_NOT_ALLOWED,
			));

			return false;
		}

		if ((new RecycleBinFilter())->isInRecycleBin($documentId))
		{
			$this->addError(new Error(
				Loc::getMessage('NOTE_FAVORITE_DOCUMENT_TRASHED'),
				self::ERROR_DOCUMENT_TRASHED,
			));

			return false;
		}

		return true;
	}
}
