<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Category;

use Bitrix\Crm\V2\Internal\Entity\Category\CategoryData;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldNotWritableException;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldValueNotAllowedException;
use Bitrix\Main\Result;

/**
 * Access to the categories of a single entity type, bound at construction time. Implementations hide
 * the legacy category layer ({@see \Bitrix\Crm\Service\Factory},
 * {@see \Bitrix\Crm\Category\Entity\Category}) and return {@see CategoryData} only.
 *
 * No read method throws: a category that does not exist is `null`, an entity type without
 * categories is an empty set.
 *
 * A write is an adapter over the same boundary and nothing more. It refuses a field the entity type
 * does not accept and a value the domain does not accept - a blank name, which the Deal boundary
 * drops from the write instead of refusing - and everything else it hands up as the boundary reports
 * it: an exception of the boundary propagates unchanged, a failed {@see Result} is returned
 * unchanged. Turning either of them into a domain error is the job of the calling scenario
 * ({@see \Bitrix\Crm\V2\Internal\Exception\Category\StorageBoundaryExceptionMapper}).
 *
 * @internal
 */
interface CategoryRepositoryInterface
{
	/**
	 * The stored category of a successful write, inside {@see Result::getData()}.
	 */
	public const DATA_KEY_CATEGORY = 'category';

	public function getById(int $id): ?CategoryData;

	/**
	 * The whole set, ordered by `sort` and then by `id`. Entity types that have a virtual category
	 * `0` (Deal, Contact, Company) get it as a regular member of the set.
	 *
	 * @return CategoryData[]
	 */
	public function getAll(): array;

	public function getDefault(): ?CategoryData;

	/**
	 * Creates a category of the entity type this repository is bound to.
	 *
	 * Field names are the domain ones - the property names of {@see CategoryData} - and their values
	 * have the type of the matching property. Which of them the entity type accepts is decided by its
	 * own category field model ({@see \Bitrix\Crm\Service\Factory::getCategoryFieldsInfo()}): Deal
	 * keeps `isDefault` read-only and has no `isSystem` / `code` at all, while a smart process has
	 * both of them read-only. `entityTypeId` is never writable - it is the state of the repository.
	 *
	 * Making the category the default one takes the flag from the category that currently holds it,
	 * so a single call can produce two writes. They are not made atomic here: the transaction belongs
	 * to the calling scenario.
	 *
	 * @param array<string, mixed> $fields
	 * @throws FieldNotWritableException A field the entity type does not accept, named by the
	 *         exception. Nothing is written.
	 * @throws FieldValueNotAllowedException A name that trims to nothing, named by the exception.
	 *         Nothing is written.
	 */
	public function add(array $fields): Result;

	/**
	 * Changes the category $id of the entity type this repository is bound to. Fields follow
	 * {@see self::add()}.
	 *
	 * @param array<string, mixed> $fields
	 * @throws FieldNotWritableException
	 * @throws FieldValueNotAllowedException
	 * @throws \Bitrix\Crm\Entry\EntryException With code `NOT_FOUND` when there is no such category.
	 */
	public function update(int $id, array $fields): Result;

	/**
	 * Deletes the category $id together with everything the boundary cascades from it - stages, role
	 * permissions, user fields.
	 *
	 * @throws \Bitrix\Crm\Entry\EntryException With code `NOT_FOUND` when there is no such category.
	 */
	public function delete(int $id): Result;

	/**
	 * Forgets what the boundary remembers about categories and stages, so that the next read goes to
	 * storage.
	 *
	 * NOT ONLY THIS ENTITY TYPE. The persistent half of it is the managed cache of the category and
	 * stage tables, and the boundary purges that by the directory of the table
	 * ({@see \Bitrix\Crm\Model\ItemCategoryTable::cleanCache()} and
	 * {@see \Bitrix\Crm\StatusTable::cleanCache()} both end in
	 * {@see \Bitrix\Main\ORM\Entity::cleanCache()}, which cleans the whole directory). The entry of a
	 * single entity type is not addressable there - the cache is keyed by the text of the query that
	 * filled it - so what this drops is every category and every stage of the installation. That is
	 * why a caller asks for it only when it has to; the in-request memos of the factory are narrowed
	 * to this entity type, but they are not the expensive half.
	 *
	 * A write purges those caches on its own, and a caller never has to ask for it. What a caller does
	 * have to ask for is the case a write cannot see: a write undone by a rollback. The boundary caches
	 * what it read while the transaction was open - the state the rollback then throws away - and the
	 * persistent part of that outlives the request
	 * ({@see \Bitrix\Crm\Model\ItemCategoryTable::getItemCategoriesByEntityTypeId()} and
	 * {@see \Bitrix\Crm\StatusTable::loadStatusesByEntityId()} both read through the query cache), so a
	 * scenario undoing a write that had reached storage drops the caches afterwards.
	 *
	 * The stages are included because a category write is never only about the category: creating one
	 * plays the scenario that fills it with stages and deleting one erases them
	 * ({@see \Bitrix\Crm\Model\ItemCategoryTable::onBeforeDelete()}), and those writes are read back
	 * inside the same transaction.
	 */
	public function forgetCaches(): void;

	/**
	 * Forgets only what the boundary memoized for the length of this request, leaving the caches that
	 * outlive it alone - the cheap half of {@see self::forgetCaches()}, narrowed to this entity type.
	 *
	 * This is what a refused write asks for when it never reached storage. The caches over storage are
	 * still telling the truth then, but the memos are not: the ORM object of a category strips its own
	 * identifier and calls itself deleted even when the deletion failed
	 * ({@see \Bitrix\Main\ORM\Objectify\EntityObject::delete()}), and the factory goes on handing that
	 * object out for the rest of the request.
	 */
	public function forgetMemos(): void;
}
