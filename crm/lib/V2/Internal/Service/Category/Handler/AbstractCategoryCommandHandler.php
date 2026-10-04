<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Handler;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\V2\Internal\Entity\Category\CategoryData;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldNotWritableException;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldValueNotAllowedException;
use Bitrix\Crm\V2\Internal\Exception\Category\StorageBoundaryExceptionMapper;
use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepository;
use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepositoryInterface;
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
 * What every write scenario of the category domain does the same way: the order of the refusals, the
 * transaction around the write and the translation of a storage boundary refusal into a
 * {@see CategoryError}.
 *
 * A refused write is always a failed {@see Result} - no exception of the storage boundary leaves this
 * layer, because a routine client refusal reaching the transport would look like a server failure
 * there. Two error codes leave: a {@see CategoryError} for a refusal about the category itself, and
 * {@see ErrorCode::ACCESS_DENIED} for a user who may not perform the action at all. The latter is
 * deliberately not a `CategoryError`: it says nothing about a category and is answered outside the
 * domain error model, the way the rest of the module answers it.
 *
 * @internal
 */
abstract class AbstractCategoryCommandHandler
{
	public function __construct(
		protected readonly CategoryAccess $access = new CategoryAccess(),
		protected readonly StorageBoundaryExceptionMapper $exceptionMapper = new StorageBoundaryExceptionMapper(),
	)
	{
	}

	protected function createRepository(int $entityTypeId): CategoryRepositoryInterface
	{
		return new CategoryRepository($entityTypeId);
	}

	/**
	 * `null` when $entityType has categories to write at all.
	 *
	 * Lead and Quote have none, and the boundary refuses a write for them in exactly this way - the
	 * repository throws {@see \Bitrix\Main\InvalidOperationException}, which the mapper classifies as
	 * this very error. The check only moves the answer ahead of the permission check, which would
	 * otherwise report a missing right for an operation the entity type does not have.
	 */
	protected function refuseUnsupportedEntityType(EntityType $entityType): ?Result
	{
		if (EntityTypeSettings::of($entityType)->isCategoriesSupported())
		{
			return null;
		}

		return self::refuse(CategoryError::OPERATION_NOT_ALLOWED_FOR_CATEGORY);
	}

	/**
	 * `null` when $userId may write categories of $entityType. A `null` $categoryId asks about the
	 * category that does not exist yet - the one being added.
	 */
	protected function refuseMissingWriteRight(EntityType $entityType, int $userId, ?int $categoryId): ?Result
	{
		if ($this->access->canWriteCategory($entityType->getId(), $userId, $categoryId))
		{
			return null;
		}

		return self::fail(ErrorCode::getAccessDeniedError());
	}

	/**
	 * The category $categoryId as a write may address it, in
	 * {@see CategoryRepositoryInterface::DATA_KEY_CATEGORY} of a successful result; otherwise the
	 * refusal that stops the write.
	 *
	 * The read right is asked first and a missing one answers the same absence a missing category
	 * does: that a category the user may not see is there is never revealed. The write right
	 * comes after existence, and its refusal is about the action - by then the user already knows the
	 * category exists, so there is nothing left to hide.
	 */
	protected function resolveCategoryToWrite(
		CategoryRepositoryInterface $repository,
		EntityType $entityType,
		int $userId,
		int $categoryId,
	): Result
	{
		$refusal = $this->refuseUnsupportedEntityType($entityType);
		if ($refusal !== null)
		{
			return $refusal;
		}

		if (!$this->access->canRead($entityType->getId(), $userId, $categoryId))
		{
			return self::refuse(CategoryError::CATEGORY_NOT_FOUND);
		}

		$category = $repository->getById($categoryId);
		if ($category === null)
		{
			return self::refuse(CategoryError::CATEGORY_NOT_FOUND);
		}

		$refusal = $this->refuseMissingWriteRight($entityType, $userId, $categoryId);

		return $refusal ?? (new Result())->setData([CategoryRepositoryInterface::DATA_KEY_CATEGORY => $category]);
	}

	/**
	 * Runs $write inside a transaction of the connection and hands its result up, translated.
	 *
	 * The transaction covers the write and nothing else - permissions and reads happen before it. It
	 * is needed because a single call of the repository can reach storage more than once and the
	 * boundary protects none of it: promoting a category to the default one first takes the flag from
	 * its current holder, creating one plays the default-stage scenario after the row is inserted, and
	 * deleting one erases the stages of the category in a loop. A refusal halfway through leaves a
	 * portal without a default category or a category with half of its stages gone.
	 *
	 * The guarantee is the one the database gives and not a line more: what a virtual category writes
	 * lives in module options ({@see \Bitrix\Crm\Category\Entity\DealDefaultCategory::save()}) and
	 * survives the rollback.
	 *
	 * A nested call is correct: the connection turns the inner transaction into a savepoint, so the
	 * rollback undoes this write alone and leaves the transaction of the caller open - see
	 * {@see self::undo()} for what the connection has to say about that.
	 *
	 * @param CategoryRepositoryInterface $repository the repository $write goes through, asked to forget
	 *        its caches when an undone write had reached storage - see {@see self::undo()}
	 * @param callable(): Result $write
	 */
	protected function writeInTransaction(CategoryRepositoryInterface $repository, callable $write): Result
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
			$this->undo($connection, $repository, false);

			return self::fail(new Error(
				CategoryError::FIELD_NOT_WRITABLE->getMessage(),
				CategoryError::FIELD_NOT_WRITABLE->value,
				['field' => $exception->getFieldName()],
			));
		}
		catch (FieldValueNotAllowedException $exception)
		{
			// Ours as well, and for the same reason: a value the domain does not accept is a client
			// refusal naming the field it is about. The repository throws it before it touches the
			// boundary at all, so there is nothing to forget here either.
			$this->undo($connection, $repository, false);

			return self::fail(new Error(
				CategoryError::FIELD_VALUE_NOT_ALLOWED->getMessage(),
				CategoryError::FIELD_VALUE_NOT_ALLOWED->value,
				['field' => $exception->getFieldName()],
			));
		}
		catch (\Throwable $exception)
		{
			$this->undo($connection, $repository, true);

			return self::fail($this->exceptionMapper->mapThrowable($exception));
		}

		if (!$result->isSuccess())
		{
			$this->undo($connection, $repository, !$this->exceptionMapper->refusedBeforeWriting($result));

			return $this->exceptionMapper->mapResult($result);
		}

		$connection->commitTransaction();

		return $result;
	}

	/**
	 * Undoes the write of {@see self::writeInTransaction()}, keeping the refusal that caused it.
	 *
	 * A nested rollback is where the connection stops being quiet: it rolls the savepoint back and
	 * then reports itself as unsupported anyway
	 * ({@see \Bitrix\Main\DB\MysqlCommonConnection::rollbackTransaction()}). By that point the write is
	 * already undone, and the complaint is about the shape of the call rather than about the category -
	 * letting it out would replace a client refusal with an exception and hand the transport a server
	 * failure instead of an answer, which is exactly what this layer exists to prevent.
	 *
	 * The caches of the boundary are dropped afterwards and not before: a rollback restores storage and
	 * nothing else, while the steps it undid have already filled those caches - the stage cascade of a
	 * deletion reads the set back on the very step that refuses
	 * ({@see \Bitrix\Crm\StatusTable::onBeforeDelete()}), and inside the transaction it reads the state
	 * the rollback is about to throw away. Left alone, that state outlives the request and the portal
	 * goes on being answered with a category that lost the stages it still has.
	 *
	 * They are dropped only when there was a write to undo, because the drop is not free and not narrow:
	 * it is the managed cache of the category and stage tables of the whole installation
	 * ({@see CategoryRepositoryInterface::forgetCaches()}). A refusal that never reached storage - a
	 * field the entity type does not accept, a category the boundary turns down before its cascade
	 * begins - leaves those caches telling the truth, and purging them would answer an everyday mistake
	 * of one administrator by making every portal read everything again. What such a refusal does leave
	 * wrong is the memo of this request, and that is dropped either way
	 * ({@see CategoryRepositoryInterface::forgetMemos()}).
	 *
	 * @param bool $writeReachedStorage whether the write got as far as changing anything. `true` is the
	 *        safe answer and the one every refusal that cannot be placed gets.
	 */
	private function undo(
		Connection $connection,
		CategoryRepositoryInterface $repository,
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
			$repository->forgetCaches();
		}
		else
		{
			$repository->forgetMemos();
		}
	}

	/**
	 * The category a successful {@see self::resolveCategoryToWrite()} or write carries.
	 */
	protected static function categoryOf(Result $result): CategoryData
	{
		return $result->getData()[CategoryRepositoryInterface::DATA_KEY_CATEGORY];
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
