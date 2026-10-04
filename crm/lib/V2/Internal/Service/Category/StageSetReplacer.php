<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\PullManager;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldNotWritableException;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldValueNotAllowedException;
use Bitrix\Crm\V2\Internal\Exception\Category\StorageBoundaryExceptionMapper;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepository;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Category\Matching\StageChangePlan;
use Bitrix\Crm\V2\Internal\Service\Category\Matching\StageMatchingStrategy;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Brings the whole stage set of one category to the state a caller asks for, in one transaction.
 *
 * Every group replacement of the domain goes through here - the REST one and the tool of the AI
 * assistant alike - and the only thing that tells them apart is the {@see StageMatchingStrategy}
 * they hand in. What is left is the same for both and lives here: the permission, the invariant of
 * {@see StageGuard}, the order the writes go in, the transaction around them and the kanban
 * notification after it.
 *
 * The order of the writes is not an implementation detail. Deletions go first because the ORM event
 * of the single success stage ({@see \Bitrix\Crm\StatusTable::onBeforeAdd()}) refuses a new success
 * stage while the previous one is still there; the layout of the whole set goes last because the
 * semantics of a stage is read off its position, so a set is only valid once every stage of it is
 * where it belongs.
 *
 * WHAT A CALLER REACHING THIS CLASS DIRECTLY DOES NOT GET. The barrier every other stage write of the
 * domain passes first - {@see Handler\AbstractStageCommandHandler::refuseStageWriteIn()} - belongs to
 * the scenario above and not here, and the permission below is not a substitute for it:
 * {@see CategoryAccess::canWriteStages()} leans on `isStagesSupported()`, which a smart process
 * answers `true` for even when its stages are switched off
 * ({@see \Bitrix\Crm\V2\Public\EntityTypeSettings}). Such an entity type gets as far as
 * {@see StageGuard}, which turns it down for having no success stage - the same
 * {@see CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE} the barrier would have answered, for another
 * reason. Reachable only by calling
 * {@see \Bitrix\Crm\Integration\AI\Function\Category\Stage\UpdateList} with an entity type other than
 * Deal; the tool the assistant is actually given goes through the Deal wrapper.
 *
 * Nothing here knows about REST or about the AI assistant: no transport DTO, no analytics, no
 * exception of either of them.
 *
 * @internal
 */
class StageSetReplacer
{
	/**
	 * The stages the category ended up with, in order, inside {@see Result::getData()} of a
	 * successful replacement.
	 */
	public const DATA_KEY_STAGES = 'stages';

	/**
	 * How much the replacement changed, as `array{added: int, renamed: int, deleted: int}`, inside
	 * {@see Result::getData()} of a successful replacement.
	 */
	public const DATA_KEY_CHANGE_COUNTS = 'changeCounts';

	public function __construct(
		protected readonly CategoryAccess $access = new CategoryAccess(),
		protected readonly StorageBoundaryExceptionMapper $exceptionMapper = new StorageBoundaryExceptionMapper(),
		protected readonly StageGuard $guard = new StageGuard(),
	)
	{
	}

	/**
	 * Replaces the stages of the category $categoryId with the ones $strategy makes of $input.
	 *
	 * The permission is asked here rather than by the caller: both scenarios above reach this method
	 * and a right checked in two places drifts apart, which is exactly what the legacy stage API did.
	 *
	 * The order the category ends up in is read off the `sort` of the stages the plan carries: a
	 * surviving stage keeps its stored one unless `toUpdate` gives it another, an added stage takes
	 * the one `toAdd` gives it, and the set is laid out in ascending order of that - an added stage
	 * ahead of a stored stage of the same `sort`. A final stage the plan says nothing about is the one
	 * exception: it keeps the end of the set whatever positions the plan takes, because a plan only
	 * ever places process stages and the boundary would not keep one behind a final stage
	 * ({@see \Bitrix\Crm\StatusTable::onBeforeAdd()}). A strategy therefore spells the order of its own
	 * stages and never has to fit them into the room the stored numbering happens to leave. The numbers
	 * themselves do not survive the call: {@see StageRepositoryInterface::applySort()} re-spaces the
	 * resulting set by the step of the module, so a strategy only has to get the order right, never the
	 * positions.
	 *
	 * The same rule moves a process stage storage kept behind a final one in front of it. No write of
	 * this domain produces that layout - the boundary refuses it - but an old funnel can hold one, and
	 * a replacement is where it is straightened out rather than carried over.
	 *
	 * @param array<int, mixed> $input the stages the caller sent, in the shape $strategy defines
	 * @return Result The resulting stage set in {@see self::DATA_KEY_STAGES} and the change counts in
	 *         {@see self::DATA_KEY_CHANGE_COUNTS}. A refusal is a failed result carrying a
	 *         {@see CategoryError} or {@see ErrorCode::ACCESS_DENIED}, and storage is left as it was.
	 */
	public function replace(
		EntityType $entityType,
		int $userId,
		int $categoryId,
		array $input,
		StageMatchingStrategy $strategy,
	): Result
	{
		$entityTypeId = $entityType->getId();
		if (!$this->access->canWriteStages($entityTypeId, $userId, $categoryId))
		{
			return self::fail(ErrorCode::getAccessDeniedError());
		}

		$repository = $this->createRepository($entityTypeId);
		$current = $repository->getAllByCategory($categoryId);

		$plan = $strategy->match($current, $input);
		if (!$plan->isSuccess())
		{
			return $plan;
		}

		// Before the transaction is opened at all: a plan breaking an invariant is turned down whole
		// rather than applied in part and rolled back.
		$allowed = $this->guard->checkSetAfterChanges($current, $plan->toDelete, $plan->toAdd);
		if (!$allowed->isSuccess())
		{
			return $allowed;
		}

		$written = $this->applyInTransaction($repository, $categoryId, $current, $plan);
		if (!$written->isSuccess())
		{
			return $written;
		}

		// After the commit and never inside it: a consumer redrawing its kanban on this event would
		// otherwise read a state that is not there yet.
		$this->notifyStagesChanged($entityType, $categoryId);

		return (new Result())->setData([
			self::DATA_KEY_STAGES => $repository->getAllByCategory($categoryId),
			self::DATA_KEY_CHANGE_COUNTS => self::changeCountsOf($plan),
		]);
	}

	protected function createRepository(int $entityTypeId): StageRepositoryInterface
	{
		return new StageRepository($entityTypeId);
	}

	protected function getConnection(): Connection
	{
		return Application::getConnection();
	}

	/**
	 * Tells the kanban of the category that its stages are no longer the ones it drew.
	 *
	 * {@see PullManager} is CRM's own layer over the pull module, so this stays outside
	 * `Internal\Integration`: the call never leaves `Bitrix\Crm`. The type name it wants is the one
	 * {@see \Bitrix\Crm\Service\Factory::getEntityName()} builds, and {@see EntityType::getName()}
	 * is the same string without a detour through the factory.
	 */
	protected function notifyStagesChanged(EntityType $entityType, int $categoryId): void
	{
		PullManager::getInstance()->sendStageUpdatedEvent(
			[],
			[
				'TYPE' => $entityType->getName(),
				'CATEGORY_ID' => $categoryId,
			],
		);
	}

	/**
	 * Runs the plan inside a transaction and hands its outcome up, translated.
	 *
	 * A refusal on any step undoes the whole replacement: a category with items sitting on a stage
	 * the plan deletes is left exactly as it was, and
	 * not with the stages the steps before that one already renamed.
	 *
	 * No exception of the storage boundary leaves this layer - a routine client refusal reaching the
	 * transport would look like a server failure there.
	 *
	 * @param StageData[] $current
	 */
	private function applyInTransaction(
		StageRepositoryInterface $repository,
		int $categoryId,
		array $current,
		StageChangePlan $plan,
	): Result
	{
		$connection = $this->getConnection();
		$connection->startTransaction();

		$writeReachedStorage = false;
		try
		{
			$result = $this->apply($repository, $categoryId, $current, $plan, $writeReachedStorage);
		}
		catch (FieldNotWritableException $exception)
		{
			// Caught ahead of the general refusal on purpose: this one is ours, it names the field the
			// write asked for, and through the boundary mapper it would become a logged failure of the
			// domain instead of the client refusal it is.
			$this->undo($connection, $repository, $categoryId, $writeReachedStorage);

			return self::fail(new Error(
				CategoryError::FIELD_NOT_WRITABLE->getMessage(),
				CategoryError::FIELD_NOT_WRITABLE->value,
				['field' => $exception->getFieldName()],
			));
		}
		catch (FieldValueNotAllowedException $exception)
		{
			// Ours as well, and for the same reason: a value the domain does not accept - a stage the
			// caller left without a name - is a client refusal naming the field it is about.
			$this->undo($connection, $repository, $categoryId, $writeReachedStorage);

			return self::fail(new Error(
				CategoryError::FIELD_VALUE_NOT_ALLOWED->getMessage(),
				CategoryError::FIELD_VALUE_NOT_ALLOWED->value,
				['field' => $exception->getFieldName()],
			));
		}
		catch (\Throwable $exception)
		{
			$this->undo($connection, $repository, $categoryId, $writeReachedStorage);

			return self::fail($this->exceptionMapper->mapThrowable($exception));
		}

		if (!$result->isSuccess())
		{
			$this->undo($connection, $repository, $categoryId, $writeReachedStorage);

			return $this->exceptionMapper->mapResult($result);
		}

		$connection->commitTransaction();

		return $result;
	}

	/**
	 * Undoes the write of {@see self::applyInTransaction()}, keeping the refusal that caused it.
	 *
	 * A nested rollback is where the connection stops being quiet: it rolls the savepoint back and
	 * then reports itself as unsupported anyway
	 * ({@see \Bitrix\Main\DB\MysqlCommonConnection::rollbackTransaction()}). By that point the write
	 * is already undone, and the complaint is about the shape of the call rather than about the
	 * stages - letting it out would replace a client refusal with an exception.
	 *
	 * The caches of the boundary are dropped afterwards and not before: a rollback restores storage
	 * and nothing else, while the steps it undid have already filled those caches - the check the
	 * refusal came from reads the stages back, and inside the transaction it reads the state the
	 * rollback is about to throw away. Left alone, that state outlives the request and a refused
	 * replacement answers with stages the category no longer has.
	 *
	 * They are dropped only when there was a write to undo: the drop is the managed cache of the stage
	 * table of the whole installation ({@see StageRepositoryInterface::forgetCaches()}), and a
	 * replacement turned down on its very first step - a stage items still stand on, which is the
	 * everyday refusal here - left those caches telling the truth. The memo of this request is brought
	 * back in line either way ({@see StageRepositoryInterface::forgetMemos()}).
	 *
	 * @param bool $writeReachedStorage whether {@see self::apply()} got as far as changing anything
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
	 * The writes of the plan, in the only order they hold together in.
	 *
	 * @param StageData[] $current
	 * @param bool $writeReachedStorage set as soon as the plan can have changed anything the caches of
	 *        {@see self::undo()} stand over, so that a refusal knows whether undoing it has to drop
	 *        them. A deletion the boundary refuses erases no stage. It does write, and unconditionally
	 *        - {@see \Bitrix\Crm\StatusTable::onBeforeDelete()} recomputes the field attributes of the
	 *        entity type after adding its refusal
	 *        ({@see \Bitrix\Crm\Attribute\FieldAttributeManager::processPhaseDeletion()}) - but that is
	 *        another table, its caches are not the ones dropped here, and the transaction of this class
	 *        rolls the row back. Past the deletions the answer is `true` whatever the outcome, because
	 *        an update writes the position of a stage before it can refuse over its colour and a layout
	 *        writes the set stage by stage.
	 */
	private function apply(
		StageRepositoryInterface $repository,
		int $categoryId,
		array $current,
		StageChangePlan $plan,
		bool &$writeReachedStorage,
	): Result
	{
		foreach ($plan->toDelete as $stage)
		{
			$deleted = $repository->delete($categoryId, $stage->stageId);
			if (!$deleted->isSuccess())
			{
				return $deleted;
			}

			$writeReachedStorage = true;
		}

		// Past the deletions every step can leave a row behind before it refuses.
		$writeReachedStorage = true;

		$storedByStageId = self::indexByStageId($current);
		foreach ($plan->toUpdate as $stage)
		{
			$fields = self::changedFieldsOf($stage, $storedByStageId[$stage->stageId] ?? null);
			if ($fields === [])
			{
				continue;
			}

			// Without reading the stage back: the answer of this scenario is the set it reads once after
			// every write ({@see self::replace()}), so a stage read per write would be read and dropped.
			$updated = $repository->update($categoryId, $stage->stageId, $fields, readBack: false);
			if (!$updated->isSuccess())
			{
				return $updated;
			}
		}

		$added = [];
		$sorts = self::sortsForNewStages($current, $plan);
		foreach (array_values($plan->toAdd) as $index => $stage)
		{
			$result = $repository->add($categoryId, self::fieldsOfNewStage($stage, $sorts[$index]));
			if (!$result->isSuccess())
			{
				return $result;
			}

			/** @var StageData $stored */
			$stored = $result->getData()[StageRepositoryInterface::DATA_KEY_STAGE];
			$added[] = ['sort' => $stage->sort, 'stageId' => $stored->stageId];
		}

		return $repository->applySort($categoryId, self::resultingOrder($current, $plan, $added));
	}

	/**
	 * The stages of the category as the plan leaves them, in the order they must stand in.
	 *
	 * The final stages the plan says nothing about close the set, in the order they already stand in:
	 * a plan places process stages alone, and its own order is therefore never weighed against the
	 * positions the final ones hold ({@see self::replace()}). A process stage storage kept behind one
	 * of them therefore moves in front of it, whatever `sort` it carries.
	 *
	 * @param StageData[] $current
	 * @param array<int, array{sort: int, stageId: string}> $added the stages the plan created, as the
	 *        identifier storage gave them and the position the plan asked for
	 * @return string[]
	 */
	private static function resultingOrder(array $current, StageChangePlan $plan, array $added): array
	{
		$deletedIds = [];
		foreach ($plan->toDelete as $stage)
		{
			$deletedIds[$stage->stageId] = true;
		}

		$requestedSortById = [];
		foreach ($plan->toUpdate as $stage)
		{
			$requestedSortById[$stage->stageId] = $stage->sort;
		}

		// [a final stage the plan leaves where it stands goes last, sort, an added stage before a
		// stored one of the same sort, position among its own kind].
		$ordered = [];
		foreach ($current as $position => $stage)
		{
			if (isset($deletedIds[$stage->stageId]))
			{
				continue;
			}

			$requestedSort = $requestedSortById[$stage->stageId] ?? null;
			$stays = $requestedSort === null && PhaseSemantics::isFinal($stage->semantics);

			$ordered[] = [(int)$stays, $requestedSort ?? $stage->sort, 1, $position, $stage->stageId];
		}

		foreach ($added as $position => $stage)
		{
			$ordered[] = [0, $stage['sort'], 0, $position, $stage['stageId']];
		}

		usort(
			$ordered,
			static fn (array $a, array $b): int => [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]],
		);

		return array_column($ordered, 4);
	}

	/**
	 * The fields an update has to carry: the ones $target spells differently from the stage the
	 * category has now. A stage the plan changes nothing about is not written at all.
	 *
	 * `sort` is never among them. The position of a stage only means anything against the other
	 * stages of the set, so the whole set is laid out once, after every other write.
	 *
	 * A `null` colour is not a value but the absence of one: it leaves the stored colour alone, the
	 * way a strategy that only renames a stage carries no colour to write.
	 *
	 * @return array<string, mixed>
	 */
	private static function changedFieldsOf(StageData $target, ?StageData $stored): array
	{
		$fields = [];

		if ($target->name !== $stored?->name)
		{
			$fields['name'] = $target->name;
		}

		if ($target->color !== null && $target->color !== $stored?->color)
		{
			$fields['color'] = $target->color;
		}

		if ($target->semantics !== $stored?->semantics)
		{
			$fields['semantics'] = $target->semantics;
		}

		return $fields;
	}

	/**
	 * The fields a new stage is created with. `sort` is the position the stage is written at and not
	 * the one it ends up standing at ({@see self::sortsForNewStages()}); where the stage really belongs
	 * is decided afterwards, when the whole set is laid out.
	 *
	 * @return array<string, mixed>
	 */
	private static function fieldsOfNewStage(StageData $stage, int $sort): array
	{
		$fields = [
			'name' => $stage->name,
			'semantics' => $stage->semantics,
			'sort' => $sort,
		];

		if ($stage->color !== null)
		{
			$fields['color'] = $stage->color;
		}

		return $fields;
	}

	/**
	 * The positions the stages of the plan are created at, in the order the plan creates them - not
	 * the positions they end up standing at, which the layout after them decides.
	 *
	 * A created stage is a process stage in both strategies of the domain
	 * ({@see Matching\MatchById}, {@see Matching\MatchByPosition}), and in front of the success stage
	 * is the only place the boundary keeps one ({@see \Bitrix\Crm\StatusTable::onBeforeAdd()}). So each
	 * of them goes one step behind the last process stage of the category and, once no room is left
	 * there, immediately in front of the success stage. That is what the repository works out for a
	 * stage arriving without a position ({@see StageRepositoryInterface::add()}) - except that working
	 * it out costs a read of the whole set, and the repository pays it once per stage created.
	 *
	 * Worked out here instead, once, off the set the plan was made against. It is the same set the
	 * repository would read: no strategy of the domain plans a final stage, {@see StageGuard} refuses a
	 * plan that would leave the category without the success stage it has, and an update of the plan
	 * never carries a position ({@see self::changedFieldsOf()}). The only stages that move while the
	 * plan is applied are therefore the ones it deletes and the ones counted here as they are created.
	 *
	 * Following the step of the layout rather than picking any free position below the success stage is
	 * what spares the layout writing a created stage a second time
	 * ({@see StageRepositoryInterface::SORT_STEP}).
	 *
	 * @param StageData[] $current
	 * @return int[] one position per stage of `toAdd`, in its order
	 */
	private static function sortsForNewStages(array $current, StageChangePlan $plan): array
	{
		$deletedIds = [];
		foreach ($plan->toDelete as $stage)
		{
			$deletedIds[$stage->stageId] = true;
		}

		$lastProcessSort = 0;
		$successSort = null;
		foreach ($current as $stage)
		{
			if (isset($deletedIds[$stage->stageId]))
			{
				continue;
			}

			if (PhaseSemantics::isSuccess($stage->semantics))
			{
				$successSort = $stage->sort;
			}
			elseif (!PhaseSemantics::isFinal($stage->semantics))
			{
				$lastProcessSort = max($lastProcessSort, $stage->sort);
			}
		}

		$sorts = [];
		for ($number = count($plan->toAdd); $number > 0; $number--)
		{
			$sort = $lastProcessSort + StageRepositoryInterface::SORT_STEP;
			if ($successSort !== null && $sort >= $successSort)
			{
				// The step does not fit between the last process stage and the success one. Landing on the
				// position of a process stage is fine: the record id breaks the tie and the created stage
				// still comes after it. Never below 1 - the boundary reads a non-positive position as no
				// position at all and puts its own default in its place ({@see \CCrmStatus::Add()}).
				$sort = max(1, $successSort - 1);
			}

			$sorts[] = $sort;
			$lastProcessSort = max($lastProcessSort, $sort);
		}

		return $sorts;
	}

	/**
	 * How much the replacement changed, counted off the plan rather than off the difference in set
	 * sizes the way the AI scenario used to count it: a plan knows which stages it renames even when
	 * it deletes and creates the same number of them.
	 *
	 * A stage of `toUpdate` counts as renamed even when the values it carries turn out to be the
	 * ones already stored: what the counts describe is the replacement the caller asked for.
	 *
	 * @return array{added: int, renamed: int, deleted: int}
	 */
	private static function changeCountsOf(StageChangePlan $plan): array
	{
		return [
			'added' => count($plan->toAdd),
			'renamed' => count($plan->toUpdate),
			'deleted' => count($plan->toDelete),
		];
	}

	/**
	 * @param StageData[] $stages
	 * @return array<string, StageData>
	 */
	private static function indexByStageId(array $stages): array
	{
		$byStageId = [];
		foreach ($stages as $stage)
		{
			$byStageId[$stage->stageId] = $stage;
		}

		return $byStageId;
	}

	private static function fail(Error $error): Result
	{
		return (new Result())->addError($error);
	}
}
