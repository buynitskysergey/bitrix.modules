<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Category;

use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Entry\EntryException;
use Bitrix\Crm\Model\ItemCategoryTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Internal\Entity\Category\CategoryData;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldNotWritableException;
use Bitrix\Crm\V2\Internal\Exception\Category\FieldValueNotAllowedException;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * @internal
 */
class CategoryRepository implements CategoryRepositoryInterface
{
	private const FIELD_IS_DEFAULT = 'isDefault';

	/**
	 * The writable contract of this repository: a domain field name and the field of the category
	 * field model that decides whether the entity type accepts it. A field outside this map is never
	 * writable - `isSystem` and `code` because no entity type allows writing them, `entityTypeId`
	 * because it is the state of the repository and not an input.
	 */
	private const MODEL_FIELD_BY_DOMAIN_FIELD = [
		'name' => 'NAME',
		'sort' => 'SORT',
		self::FIELD_IS_DEFAULT => 'IS_DEFAULT',
	];

	public function __construct(
		private readonly int $entityTypeId,
	)
	{
	}

	public function getById(int $id): ?CategoryData
	{
		$factory = $this->getFactory();
		if ($factory === null)
		{
			return null;
		}

		$category = $factory->getCategory($id);

		return $category === null ? null : $this->toData($factory, $category);
	}

	/**
	 * @return CategoryData[]
	 */
	public function getAll(): array
	{
		$factory = $this->getFactory();
		if ($factory === null)
		{
			return [];
		}

		$categories = array_map(
			fn (Category $category): CategoryData => $this->toData($factory, $category),
			$factory->getCategories(),
		);

		usort(
			$categories,
			static fn (CategoryData $a, CategoryData $b): int => [$a->sort, $a->id] <=> [$b->sort, $b->id],
		);

		return $categories;
	}

	public function getDefault(): ?CategoryData
	{
		$factory = $this->getFactory();
		if ($factory === null)
		{
			return null;
		}

		$category = $factory->getDefaultCategory();

		return $category === null ? null : $this->toData($factory, $category);
	}

	public function add(array $fields): Result
	{
		$factory = $this->requireFactory();

		return $this->write($factory, $factory->createCategory(), $fields);
	}

	public function update(int $id, array $fields): Result
	{
		$factory = $this->requireFactory();

		return $this->write($factory, $this->requireCategory($factory, $id), $fields);
	}

	public function delete(int $id): Result
	{
		$factory = $this->requireFactory();

		return $this->requireCategory($factory, $id)->delete();
	}

	public function forgetCaches(): void
	{
		// The persistent ones first: they are the only ones that outlive the request, and they are what
		// the memos of the factory are filled from.
		ItemCategoryTable::cleanCache();
		StatusTable::cleanCache();

		$this->forgetMemos();
	}

	public function forgetMemos(): void
	{
		$factory = $this->getFactory();
		if ($factory !== null)
		{
			$factory->clearCategoriesCache();
			$factory->purgeStagesCache();
		}
	}

	private function write(Factory $factory, Category $category, array $fields): Result
	{
		$this->assertFieldsWritable($factory, array_keys($fields));
		self::assertFieldValuesAllowed($fields);

		try
		{
			$result = $this->releaseCurrentDefault($factory, $category, $fields);
			if ($result->isSuccess())
			{
				$this->applyFields($category, $fields);
				$result = $category->save();
			}
		}
		finally
		{
			// Whatever the outcome is, the category set memoized by the factory no longer matches
			// storage. The boundary purges that memo only when a category is deleted.
			$factory->clearCategoriesCache();
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		return (new Result())->setData([
			self::DATA_KEY_CATEGORY => $this->requireStored((int)$category->getId()),
		]);
	}

	/**
	 * A field the entity type does not accept is refused before the boundary is touched at all: a
	 * read-only field makes the setter of a category throw
	 * ({@see \Bitrix\Crm\Category\Entity\DealCategory::setIsDefault()}), and
	 * {@see \Bitrix\Crm\Model\ItemCategoryTable::onBeforeUpdate()} even drops such a field silently,
	 * which a write must never look like.
	 *
	 * @param string[] $fieldNames
	 * @throws FieldNotWritableException
	 */
	private function assertFieldsWritable(Factory $factory, array $fieldNames): void
	{
		$modelFields = $factory->getCategoryFieldsInfo();

		foreach ($fieldNames as $fieldName)
		{
			$modelFieldName = self::MODEL_FIELD_BY_DOMAIN_FIELD[$fieldName] ?? null;
			$modelField = $modelFieldName === null ? null : ($modelFields[$modelFieldName] ?? null);

			if ($modelField === null || \CCrmFieldInfoAttr::isFieldReadOnly($modelField))
			{
				throw new FieldNotWritableException((string)$fieldName);
			}
		}
	}

	/**
	 * A blank name is refused before the boundary is touched at all, and blanks are trimmed off first
	 * because the boundary trims before it looks: {@see \Bitrix\Crm\Category\DealCategory::update()}
	 * drops a name that comes out empty from the write instead of refusing it, so a rename to nothing
	 * would report success and leave the stored name where it is. A smart process is refused by the
	 * required-field check of {@see ItemCategoryTable}, and one entity type answering a request
	 * differently from another is what this rule takes away: a category without a name is not a
	 * category, whichever entity type it belongs to.
	 *
	 * @param array<string, mixed> $fields
	 * @throws FieldValueNotAllowedException
	 */
	private static function assertFieldValuesAllowed(array $fields): void
	{
		if (array_key_exists('name', $fields) && trim((string)$fields['name']) === '')
		{
			throw new FieldValueNotAllowedException('name');
		}
	}

	/**
	 * Only one category of an entity type is the default one, so the flag has to be taken from its
	 * current holder first - the same order the existing category controller writes it in
	 * ({@see \Bitrix\Crm\Controller\Category::makeCurrentDefaultCategoryNotDefault()}).
	 */
	private function releaseCurrentDefault(Factory $factory, Category $category, array $fields): Result
	{
		if (!(bool)($fields[self::FIELD_IS_DEFAULT] ?? false))
		{
			return new Result();
		}

		$currentDefault = $factory->getDefaultCategory();
		if ($currentDefault === null || $currentDefault->getId() === $category->getId())
		{
			return new Result();
		}

		$currentDefault->setIsDefault(false);

		return $currentDefault->save();
	}

	private function applyFields(Category $category, array $fields): void
	{
		if (array_key_exists('name', $fields))
		{
			$category->setName((string)$fields['name']);
		}

		if (array_key_exists('sort', $fields))
		{
			$category->setSort((int)$fields['sort']);
		}

		if (array_key_exists(self::FIELD_IS_DEFAULT, $fields))
		{
			$category->setIsDefault((bool)$fields[self::FIELD_IS_DEFAULT]);
		}
	}

	/**
	 * `null` for an unknown entity type and for an entity type that has no categories at all: the
	 * legacy layer throws {@see \Bitrix\Main\NotSupportedException} from `getCategories()` for Lead
	 * and Quote, and a read must not leak boundary exceptions.
	 */
	private function getFactory(): ?Factory
	{
		$factory = Container::getInstance()->getFactory($this->entityTypeId);

		return $factory?->isCategoriesSupported() ? $factory : null;
	}

	/**
	 * An entity type without categories has no category to write either, and that is exactly what the
	 * boundary says about an unsupported category operation - the refusal of a virtual category has
	 * the same shape.
	 */
	private function requireFactory(): Factory
	{
		$factory = $this->getFactory();
		if ($factory === null)
		{
			throw new InvalidOperationException("Entity type {$this->entityTypeId} has no categories to write.");
		}

		return $factory;
	}

	/**
	 * The Deal boundary answers a missing category with `EntryException(NOT_FOUND)`
	 * ({@see \Bitrix\Crm\Category\DealCategory::delete()}), while the shared item-category boundary is
	 * unreachable without the object. Both entity types get the same refusal here.
	 */
	private function requireCategory(Factory $factory, int $id): Category
	{
		$category = $factory->getCategory($id);
		if ($category === null)
		{
			throw new EntryException($this->entityTypeId, $id, [], EntryException::NOT_FOUND);
		}

		return $category;
	}

	/**
	 * The result of a write is read back from storage instead of being assembled from the input: the
	 * boundary fills in what the caller did not pass, and a virtual category stores its state outside
	 * the category tables altogether.
	 */
	private function requireStored(int $id): CategoryData
	{
		$stored = $this->getById($id);
		if ($stored === null)
		{
			throw new ObjectNotFoundException(
				"Category {$id} of entity type {$this->entityTypeId} is not readable right after a successful write."
			);
		}

		return $stored;
	}

	/**
	 * Whether an attribute exists at all is decided by the category field model of the entity type,
	 * not by the category class: the legacy base entity answers `false` / `''` even where the
	 * attribute is absent.
	 */
	private function toData(Factory $factory, Category $category): CategoryData
	{
		$fields = $factory->getCategoryFieldsInfo();

		return new CategoryData(
			id: (int)$category->getId(),
			entityTypeId: $category->getEntityTypeId(),
			name: $category->getName(),
			sort: $category->getSort(),
			isDefault: $category->getIsDefault(),
			isSystem: isset($fields['IS_SYSTEM']) ? $category->getIsSystem() : null,
			code: isset($fields['CODE']) ? $category->getCode() : null,
		);
	}
}
