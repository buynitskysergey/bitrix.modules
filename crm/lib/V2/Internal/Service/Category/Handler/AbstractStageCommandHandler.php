<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Handler;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldNotWritableException;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldValueNotAllowedException;
use Bitrix\Crm\V2\Internal\Exception\Category\StorageBoundaryExceptionMapper;
use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepository;
use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepositoryInterface;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepository;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * What every write scenario of a stage does the same way: the order of the refusals and the
 * translation of a storage boundary refusal into a {@see CategoryError}.
 *
 * A stage of this domain always belongs to a category, and the category is what the refusals are
 * ordered around: an entity type whose categories carry no stages is answered before permissions,
 * a category the user may not read is answered as a category that is not there, and only a user who
 * already knows the category exists is told about a missing right.
 *
 * The write goes inside a transaction, because one of these scenarios is a single row of
 * `b_crm_status` but not a single write of it: clearing the colour of a stage takes a second write
 * past the boundary, which prepends `#` to every colour it is given
 * ({@see \Bitrix\Crm\V2\Internal\Repository\Category\StageRepository::update()}). A refusal of that
 * second write - a failing database, an ORM event handler of another module - would otherwise leave
 * the name, the semantics and the position of the stage already changed. The category scenarios open
 * one for the same reason; {@see AbstractCategoryCommandHandler} is where that lives.
 *
 * @internal
 */
abstract class AbstractStageCommandHandler
{
	public function __construct(
		protected readonly CategoryAccess $access = new CategoryAccess(),
		protected readonly StorageBoundaryExceptionMapper $exceptionMapper = new StorageBoundaryExceptionMapper(),
	)
	{
	}

	protected function createRepository(int $entityTypeId): StageRepositoryInterface
	{
		return new StageRepository($entityTypeId);
	}

	protected function createCategoryRepository(int $entityTypeId): CategoryRepositoryInterface
	{
		return new CategoryRepository($entityTypeId);
	}

	/**
	 * `null` when $userId may write the stages of the category $categoryId of $entityType; otherwise
	 * the refusal that stops the write.
	 *
	 * The read right is asked before the category is read at all, and a missing one answers the same
	 * absence a missing category does: the stages of a category never reveal that a category the user
	 * may not see is there. The write right comes after existence, and its
	 * refusal is about the action - by then the user already knows the category exists, so there is
	 * nothing left to hide.
	 */
	protected function refuseStageWriteIn(EntityType $entityType, int $userId, int $categoryId): ?Result
	{
		$refusal = self::refuseEntityTypeWithoutStagesInCategories($entityType);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$entityTypeId = $entityType->getId();
		if (!$this->access->canRead($entityTypeId, $userId, $categoryId))
		{
			return self::refuse(CategoryError::CATEGORY_NOT_FOUND);
		}

		if ($this->createCategoryRepository($entityTypeId)->getById($categoryId) === null)
		{
			return self::refuse(CategoryError::CATEGORY_NOT_FOUND);
		}

		if (!$this->access->canWriteStages($entityTypeId, $userId, $categoryId))
		{
			return self::fail(ErrorCode::getAccessDeniedError());
		}

		return null;
	}

	/**
	 * `null` when the stages of $entityType live in categories, which is what a stage of this domain
	 * is addressed by.
	 *
	 * Both halves are needed and neither can be dropped. Contact and Company have categories without
	 * stages, and the boundary would refuse them itself; Lead and Quote have stages without having
	 * categories, and the boundary would not refuse them at all - it would write the stage into the
	 * single stage set of the entity type, past the category the request named. The read side of the
	 * domain draws the same line: {@see CategoryAccess::canRead()} answers `false` for an entity type
	 * whose categories are not supported, so an entity type this check let through would be refused
	 * for a missing right it cannot ever be granted.
	 */
	private static function refuseEntityTypeWithoutStagesInCategories(EntityType $entityType): ?Result
	{
		$settings = EntityTypeSettings::of($entityType);
		if ($settings->isCategoriesSupported() && $settings->hasStages())
		{
			return null;
		}

		return self::refuse(CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE);
	}

	/**
	 * Runs $write inside a transaction of the connection and hands its result up, translated.
	 *
	 * The transaction covers the write and nothing else - permissions, the stage itself and the state
	 * of the whole set are read before it opens.
	 *
	 * A refused write is always a failed {@see Result} - no exception of the storage boundary leaves
	 * this layer, because a routine client refusal reaching the transport would look like a server
	 * failure there.
	 *
	 * A nested call is correct: the connection turns the inner transaction into a savepoint, so the
	 * rollback undoes this write alone and leaves the transaction of the caller open - see
	 * {@see self::undo()} for what the connection has to say about that.
	 *
	 * Whether undoing a refusal has to drop the caches of the boundary is the refusal's own answer
	 * ({@see StageRepositoryInterface::DATA_KEY_WRITE_REACHED_STORAGE}), and every refusal a repository
	 * of stages can hand up states it: the scenario above it sees one failed result whichever write of
	 * it refused, and only the repository knows which. A refusal that says nothing gets the safe
	 * assumption - see {@see self::writeReachedStorage()}.
	 *
	 * @param StageRepositoryInterface $repository the repository $write goes through, asked to forget
	 *        its caches when an undone write had reached storage - see {@see self::undo()}
	 * @param callable(): Result $write
	 */
	protected function writeInTransaction(
		StageRepositoryInterface $repository,
		int $categoryId,
		callable $write,
	): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$result = $write();
		}
		catch (FieldNotWritableException $exception)
		{
			// Caught ahead of the general refusal on purpose: this one is ours, it names the field the
			// write asked for, and through the boundary mapper it would become a logged failure of the
			// domain instead of the client refusal it is. The repository throws it before it touches the
			// boundary at all, so there is nothing to forget.
			$this->undo($connection, $repository, $categoryId, false);

			return self::fail(new Error(
				CategoryError::FIELD_NOT_WRITABLE->getMessage(),
				CategoryError::FIELD_NOT_WRITABLE->value,
				['field' => $exception->getFieldName()],
			));
		}
		catch (FieldValueNotAllowedException $exception)
		{
			// Ours as well, and for the same reason: a value the domain does not accept is a client
			// refusal naming the field it is about, and the repository throws it before the boundary.
			$this->undo($connection, $repository, $categoryId, false);

			return self::fail(new Error(
				CategoryError::FIELD_VALUE_NOT_ALLOWED->getMessage(),
				CategoryError::FIELD_VALUE_NOT_ALLOWED->value,
				['field' => $exception->getFieldName()],
			));
		}
		catch (\Throwable $exception)
		{
			$this->undo($connection, $repository, $categoryId, true);

			return self::fail($this->exceptionMapper->mapThrowable($exception));
		}

		if (!$result->isSuccess())
		{
			$this->undo($connection, $repository, $categoryId, self::writeReachedStorage($result));

			return $this->exceptionMapper->mapResult($result);
		}

		$connection->commitTransaction();

		return $result;
	}

	/**
	 * Undoes the write of {@see self::writeInTransaction()}, keeping the refusal that caused it.
	 *
	 * A nested rollback is where the connection stops being quiet: it rolls the savepoint back and then
	 * reports itself as unsupported anyway
	 * ({@see \Bitrix\Main\DB\MysqlCommonConnection::rollbackTransaction()}). By that point the write is
	 * already undone, and the complaint is about the shape of the call rather than about the stage -
	 * letting it out would replace a client refusal with an exception and hand the transport a server
	 * failure instead of an answer, which is exactly what this layer exists to prevent.
	 *
	 * The caches of the boundary are dropped afterwards and not before: a rollback restores storage and
	 * nothing else, while the write it undid has already filled those caches - the ORM events of the
	 * stage table read the whole set back through the managed cache and hand it to the field attribute
	 * manager ({@see \Bitrix\Crm\StatusTable::onAfterUpdate()}), and inside the transaction they read
	 * the state the rollback is about to throw away. Left alone, that state outlives the request and
	 * the portal goes on being answered with a stage that was never stored.
	 *
	 * They are dropped only when there was a write to undo, because the drop is not free and not narrow:
	 * it is the managed cache of the stage table of the whole installation
	 * ({@see StageRepositoryInterface::forgetCaches()}). A refusal that never reached storage - a field
	 * the domain does not write, a stage items still stand on, which is the everyday refusal here -
	 * leaves those caches telling the truth, and purging them would answer an everyday mistake of one
	 * administrator by making every portal read everything again. What such a refusal does leave wrong
	 * is the memo of this request, and that is dropped either way
	 * ({@see StageRepositoryInterface::forgetMemos()}).
	 *
	 * @param bool $writeReachedStorage whether the write got as far as changing anything. `true` is the
	 *        safe answer and the one every refusal that cannot be placed gets.
	 */
	private function undo(
		Connection $connection,
		StageRepositoryInterface $repository,
		int $categoryId,
		bool $writeReachedStorage,
	): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}

		if ($writeReachedStorage)
		{
			$repository->forgetCaches($categoryId);
		}
		else
		{
			$repository->forgetMemos($categoryId);
		}
	}

	/**
	 * Whether the refusal $result had already changed a row, as the refusal itself says.
	 *
	 * The repository states this on every refusal it hands up
	 * ({@see StageRepositoryInterface::DATA_KEY_WRITE_REACHED_STORAGE}), and it is the only layer that
	 * can: a scenario of two writes hands one failed result up whichever of them refused. A refusal that
	 * says nothing is therefore not a case of this domain but a repository that stopped answering, and
	 * it gets `true` - the answer that costs a cache drop rather than an installation answered with a
	 * stage that was never stored.
	 */
	private static function writeReachedStorage(Result $result): bool
	{
		$stated = $result->getData()[StageRepositoryInterface::DATA_KEY_WRITE_REACHED_STORAGE] ?? null;

		return is_bool($stated) ? $stated : true;
	}

	protected static function refuse(CategoryError $error): Result
	{
		return self::fail($error->toError());
	}

	protected static function fail(Error $error): Result
	{
		return (new Result())->addError($error);
	}
}
