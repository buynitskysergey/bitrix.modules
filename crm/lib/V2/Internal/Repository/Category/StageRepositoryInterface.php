<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Category;

use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldNotWritableException;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldValueNotAllowedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * Access to the stages of a single entity type, bound at construction time. Implementations hide
 * the legacy status layer ({@see \Bitrix\Crm\StatusTable}, {@see \Bitrix\Crm\EO_Status},
 * {@see \CCrmStatus}) and return {@see StageData} only.
 *
 * The category is always part of the request - of a read and of a write alike: resolving the
 * default category on the legacy side creates one for smart processes
 * ({@see \Bitrix\Crm\Service\Factory\Dynamic::getStagesEntityId()}), and no operation of this
 * repository may create a category as a side effect.
 *
 * No read method throws: a stage that does not exist is `null`, a category whose stages cannot be
 * read is an empty set.
 *
 * A write is an adapter over the same boundary and nothing more. It refuses a field that is not
 * part of the write contract and a value the domain does not accept - a blank name, which the
 * boundary drops from the write instead of refusing - and everything else it hands up as the
 * boundary reports it: an exception of the boundary propagates unchanged, a failed {@see Result} is
 * returned unchanged. Turning either of them into a domain error is the job of the calling scenario
 * ({@see \Bitrix\Crm\V2\Internal\Exception\Category\StorageBoundaryExceptionMapper}). The rules the
 * boundary does not hold - a system stage is never deleted, a category keeps its success stage -
 * belong to {@see \Bitrix\Crm\V2\Internal\Service\Category\StageGuard} and are not checked here.
 *
 * Where a read of an entity type without stages is an empty set, a write of one is
 * {@see \Bitrix\Main\InvalidOperationException}: it is a mistake of the caller, not an outcome.
 *
 * @internal
 */
interface StageRepositoryInterface
{
	/**
	 * The stored stage of a successful write, inside {@see Result::getData()}.
	 */
	public const DATA_KEY_STAGE = 'stage';

	/**
	 * Whether a refused write had already changed a row when it was refused, inside
	 * {@see Result::getData()} of that refusal as a `bool`.
	 *
	 * Undoing a write costs what the write reached: a rollback restores the row, and the caches the
	 * undone steps filled have to be dropped on top of that - the managed cache of the stage table of
	 * the whole installation, which is neither free nor narrow
	 * ({@see self::forgetCaches()}). A refusal that never got past the checks left those caches
	 * telling the truth, and only the repository knows which of the two happened: the scenario above
	 * it sees one failed result either way. So the answer travels with the refusal.
	 *
	 * Every refusal of a write carries it: each of them is one call of the boundary the repository
	 * placed itself, so there is no refusal of this contract left to guess at. Absence is therefore not
	 * a case of this domain but a repository that stopped answering, and it is read as the safe
	 * assumption - the caller drops the caches.
	 */
	public const DATA_KEY_WRITE_REACHED_STORAGE = 'writeReachedStorage';

	/**
	 * The distance {@see self::applySort()} lays two neighbouring stages of a set out at: the step the
	 * whole module keeps stage sorts at ({@see \CCrmStatus::DEFAULT_SORT}).
	 *
	 * It is published for the caller that has to name a `sort` of its own before the layout runs - a
	 * stage created at the position the layout would give it anyway is one the layout then leaves
	 * alone, and that is a write saved per stage. Nothing else about positions becomes an input: a
	 * layout still spells the order and never the numbers.
	 */
	public const SORT_STEP = 10;

	/**
	 * Domain field names a write accepts - the property names of {@see StageData}. `stageId`,
	 * `categoryId` and `isSystem` are absent on purpose: the first two are the address of the stage
	 * and the boundary refuses to move a stage anywhere, and a stage this domain creates is never a
	 * system one.
	 */
	public const WRITABLE_FIELDS = ['name', 'color', 'semantics', 'sort'];

	public function getById(int $categoryId, string $stageId): ?StageData;

	/**
	 * The stage $stageId in whichever of $categoryIds holds it, or `null` when none of them does and
	 * when the entity type has no such stage at all.
	 *
	 * The set of categories is an input rather than something this method works out: which categories
	 * a caller may look into is a decision of that caller
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess::getReadableCategoryIds()}), and a
	 * stage outside the set is not found. A category the entity type does not have is skipped.
	 *
	 * @param int[] $categoryIds
	 */
	public function findById(string $stageId, array $categoryIds): ?StageData;

	/**
	 * The stages of one category, ordered by `sort` and then by stage record id.
	 *
	 * @return StageData[]
	 */
	public function getAllByCategory(int $categoryId): array;

	/**
	 * Creates a stage in the category $categoryId of the entity type this repository is bound to.
	 * The whole stored identifier of the new stage - already namespaced by category where the entity
	 * type namespaces it - is handed up inside the result and is not an input.
	 *
	 * A `sort` the caller did not pass is the one that puts the stage after the last stage in
	 * process semantics rather than at the end of the set: the end of the set is behind the success
	 * stage, and there the boundary refuses the stage or turns it into a failure one
	 * ({@see \Bitrix\Crm\StatusTable::onBeforeAdd()}).
	 *
	 * @param array<string, mixed> $fields {@see self::WRITABLE_FIELDS}
	 * @throws FieldNotWritableException A field outside the write contract, named by the exception.
	 *         Nothing is written.
	 * @throws FieldValueNotAllowedException A name that trims to nothing, named by the exception.
	 *         Nothing is written.
	 * @throws \Bitrix\Crm\Entry\EntryException With code `NOT_FOUND` when the entity type has no
	 *         such category.
	 */
	public function add(int $categoryId, array $fields): Result;

	/**
	 * Changes the stage $stageId of the category $categoryId. Fields follow {@see self::add()}; a
	 * field the caller did not mention keeps its stored value.
	 *
	 * @param array<string, mixed> $fields
	 * @param bool $readBack whether the stored stage is handed up in {@see self::DATA_KEY_STAGE}.
	 *        Reading it back costs a read of the row per write, and the caller that changes a whole
	 *        set of stages does not look at any of them
	 *        ({@see \Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer}): it answers with the
	 *        set it reads once at the end. A single stage write is the other case - the stage it
	 *        answers with is what the boundary made of the request - and that is why the default is
	 *        to read. `false` leaves the data of a successful result empty; a refusal is unaffected.
	 * @throws FieldNotWritableException
	 * @throws FieldValueNotAllowedException
	 * @throws \Bitrix\Crm\Entry\EntryException With code `NOT_FOUND` when the entity type has no
	 *         such category.
	 * @throws ObjectNotFoundException When the category has no such stage. Resolving the stage is
	 *         the job of the calling scenario - it needs the stage itself anyway - and so is the
	 *         {@see \Bitrix\Crm\V2\Public\Entity\Category\CategoryError::STAGE_NOT_FOUND} it answers
	 *         with.
	 */
	public function update(int $categoryId, string $stageId, array $fields, bool $readBack = true): Result;

	/**
	 * Deletes the stage $stageId of the category $categoryId.
	 *
	 * @throws \Bitrix\Crm\Entry\EntryException With code `NOT_FOUND` when the entity type has no
	 *         such category.
	 * @throws ObjectNotFoundException When the category has no such stage, as {@see self::update()}.
	 */
	public function delete(int $categoryId, string $stageId): Result;

	/**
	 * Lays the stages of the category $categoryId out in the order $stageIds names them in. The
	 * positions themselves are not an input: the step between two neighbouring stages is the one the
	 * whole module keeps them at ({@see \CCrmStatus::DEFAULT_SORT}), and a caller spelling positions
	 * out would have to know it. A stage already standing where it is asked to stand is not written.
	 *
	 * The argument is the whole set and not a part of it: a position only means anything against the
	 * other stages of the same category, and stages left out of the order would keep positions of the
	 * previous one among stages that already have the new.
	 *
	 * Whether the order makes sense is not decided here - a process stage behind the success one is
	 * refused by {@see \Bitrix\Crm\V2\Internal\Service\Category\StageGuard}. Neither is the operation
	 * atomic on its own: it writes stage by stage and stops at the first refusal, so the caller runs
	 * it inside a transaction of its own.
	 *
	 * @param string[] $stageIds Every stage of the category exactly once, in the order they must
	 *        stand in.
	 * @throws \Bitrix\Crm\Entry\EntryException With code `NOT_FOUND` when the entity type has no
	 *         such category.
	 * @throws ObjectNotFoundException When the category has no such stage, as {@see self::update()}.
	 * @throws ArgumentException When the order is not the whole set of stages of the category: a
	 *         stage named twice or a stage of the category left out.
	 */
	public function applySort(int $categoryId, array $stageIds): Result;

	/**
	 * Forgets what the boundary remembers about stages, so that the next read goes to storage.
	 *
	 * NOT ONLY THIS CATEGORY. The persistent half of it is the managed cache of the stage table, and
	 * the boundary purges that by the directory of the table ({@see \Bitrix\Crm\StatusTable::cleanCache()}
	 * ends in {@see \Bitrix\Main\ORM\Entity::cleanCache()}, which cleans the whole directory). The entry
	 * of a single category is not addressable there - the cache is keyed by the text of the query that
	 * filled it - so what this drops is every stage of the installation. $categoryId only says whose
	 * in-request memo to drop along with it, and that memo is not the expensive half.
	 *
	 * A write purges those caches on its own, and a caller never has to ask for it. What a caller does
	 * have to ask for is the case a write cannot see: a write undone by a rollback. The boundary
	 * caches what it read while the transaction was open - the state the rollback then throws away -
	 * and the persistent part of that outlives the request
	 * ({@see \Bitrix\Crm\StatusTable::loadStatusesByEntityId()} reads through the managed cache), so a
	 * scenario undoing a write that had reached storage drops the caches afterwards.
	 *
	 * Does nothing for a category the entity type does not have.
	 */
	public function forgetCaches(int $categoryId): void;

	/**
	 * Forgets only what the boundary memoized for the length of this request, leaving the caches that
	 * outlive it alone - the cheap half of {@see self::forgetCaches()}, narrowed to $categoryId.
	 *
	 * This is what a refused write asks for when it never reached storage: the caches over storage are
	 * still telling the truth then, and only the memo of this request has to be brought back in line
	 * with them.
	 *
	 * Does nothing for a category the entity type does not have.
	 */
	public function forgetMemos(int $categoryId): void;
}
