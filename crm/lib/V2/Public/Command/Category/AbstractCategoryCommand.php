<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Category;

use Bitrix\Crm\V2\Internal\Entity\Category\StageData;
use Bitrix\Crm\V2\Internal\Repository\Category\CategoryRepositoryInterface;
use Bitrix\Crm\V2\Internal\Repository\Category\StageRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Category\Category;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\Entity\Category\Stage;
use Bitrix\Crm\V2\Public\Entity\Category\StageCollection;
use Bitrix\Crm\V2\Public\Entity\Category\StageSemantics;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Result;

/**
 * What every write of the category (pipeline) domain has in common: the user it is performed as, the
 * scope it is performed in and the shape of its answer.
 *
 * A command is run once through {@see self::run()} and always answers a {@see Result}: a refusal the
 * caller can act on is a failed result carrying a {@see CategoryError} code, or
 * {@see \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED} for a user who may not perform the action at
 * all. Nothing of the storage boundary is thrown out of here. The one exception left is a misuse of
 * the contract itself - a category or a stage that does not say what it is, or an entity type that is
 * not a CRM item type: those are {@see ArgumentException}, the way {@see EntityType} and
 * {@see \Bitrix\Crm\V2\Public\ItemId} already answer an argument that cannot be meant seriously.
 *
 * PERMISSIONS. Every command checks them, and there is no way to ask it not to: configuring the
 * categories of a portal has no system caller. The scope therefore changes nothing about the write -
 * it says where the write came from, for the journal and for the transport that reports it.
 *
 * ADDRESSING. A write is addressed by three things at most - the entity type, the category and, for
 * a stage, the stage - and a command names only the ones nothing else it is given already carries.
 * {@see Category} carries its entity type and its identifier, so a write of a category takes the
 * category alone. {@see Stage} carries its category but no entity type, so a write of a stage takes
 * the entity type beside it. Where there is no entity to take - a deletion, and a group replacement
 * whose {@see StageCollection} may legitimately be empty - the address is spelled out in plain
 * arguments. Three shapes, one rule; and none of them can be brought to the others now without
 * changing a published signature.
 */
abstract class AbstractCategoryCommand implements CommandInterface
{
	private Scope $scope = Scope::Manual;

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	abstract protected function execute(): Result;

	public function run(): Result
	{
		return $this->execute();
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getScope(): Scope
	{
		return $this->scope;
	}

	public function setScope(Scope $scope): static
	{
		$this->scope = $scope;

		return $this;
	}

	/**
	 * The entity type the category belongs to.
	 *
	 * @throws ArgumentException The category names no entity type, or names one that has no items.
	 */
	protected static function entityTypeOf(Category $category): EntityType
	{
		$entityTypeId = $category->getEntityTypeId();
		if ($entityTypeId === null)
		{
			throw new ArgumentException('A category of a write must name its entity type.', 'category');
		}

		return EntityType::fromId($entityTypeId);
	}

	/**
	 * The category a write addresses.
	 *
	 * @throws ArgumentException The category names no identifier. `0` is one - it is the virtual
	 * category of Deal and not a missing value.
	 */
	protected static function idOf(Category $category): int
	{
		$id = $category->getId();
		if ($id === null)
		{
			throw new ArgumentException('A category of a write must name its identifier.', 'category');
		}

		return $id;
	}

	/**
	 * The fields the write is to carry - the ones the caller has given a value and only them
	 * ({@see self::valuesOfChangedFields()}), so that a field left alone keeps its stored value.
	 *
	 * Which of them the entity type accepts is not decided here: a field it does not is refused with
	 * {@see CategoryError::FIELD_NOT_WRITABLE} naming that field, rather than dropped on the way.
	 *
	 * @return array<string, mixed>
	 */
	protected static function fieldsToWrite(Category $category): array
	{
		$values = [
			Category::name => $category->getName(),
			Category::sort => $category->getSort(),
			Category::isDefault => $category->getIsDefault(),
			Category::isSystem => $category->getIsSystem(),
			Category::code => $category->getCode(),
		];

		return self::valuesOfChangedFields($values, $category->getChangedFieldNames());
	}

	/**
	 * The fields of $values the caller has set to a value: a field set to `null` says nothing and
	 * stays out of the write, so the stored value of that field is kept. That is what a `null` means
	 * everywhere in this domain - a value that was not given rather than one that is empty - and a
	 * field a write does not carry is neither written nor refused for being unwritable.
	 *
	 * @param array<string, mixed> $values
	 * @param string[] $changedFieldNames
	 * @return array<string, mixed>
	 */
	private static function valuesOfChangedFields(array $values, array $changedFieldNames): array
	{
		$fields = array_intersect_key($values, array_flip($changedFieldNames));

		return array_filter($fields, static fn ($value): bool => $value !== null);
	}

	/**
	 * The answer of a write: an empty successful {@see Result}, or the refusal as it came.
	 *
	 * A successful write is reported through the category it was given rather than through the result:
	 * the category is filled with what storage now holds - the identifier a new one received, the
	 * fields the write did not mention, the ones the domain filled in for it - and the change marks are
	 * dropped, so the same object can be handed to the next write without repeating this one. It is the
	 * way an item of V2 already reports its write ({@see \Bitrix\Crm\V2\Public\Command\Item\AddItemCommand}).
	 */
	protected static function applyWriteResult(Category $category, Result $result): Result
	{
		if (!$result->isSuccess())
		{
			return $result;
		}

		self::fill($category, $result);

		return new Result();
	}

	/**
	 * Copies the category storage now holds - the one the domain read back after the write - into the
	 * category the caller handed in.
	 */
	private static function fill(Category $category, Result $result): void
	{
		$stored = $result->getData()[CategoryRepositoryInterface::DATA_KEY_CATEGORY];

		$category
			->setId($stored->id)
			->setEntityTypeId($stored->entityTypeId)
			->setName($stored->name)
			->setSort($stored->sort)
			->setIsDefault($stored->isDefault)
			->setIsSystem($stored->isSystem)
			->setCode($stored->code)
		;
		$category->resetChangedFields();
	}

	/**
	 * The category a write of a stage stands in. A stage means nothing outside its category, so the
	 * category is part of the address of every stage rather than of its content.
	 *
	 * @throws ArgumentException The stage names no category. `0` is one - it is the virtual category
	 * of Deal and not a missing value.
	 */
	protected static function stageCategoryIdOf(Stage $stage): int
	{
		$categoryId = $stage->getCategoryId();
		if ($categoryId === null)
		{
			throw new ArgumentException('A stage of a write must name its category.', 'stage');
		}

		return $categoryId;
	}

	/**
	 * The stage a write addresses, by the whole identifier it is stored under.
	 *
	 * @throws ArgumentException The stage names no identifier.
	 */
	protected static function stageIdOf(Stage $stage): string
	{
		$stageId = $stage->getStageId();
		if ($stageId === null)
		{
			throw new ArgumentException('A stage of a write must name its identifier.', 'stage');
		}

		return $stageId;
	}

	/**
	 * The fields a write of a stage is to carry - {@see self::fieldsToWrite()} for a stage, `null`
	 * reading the same way it does there: a colour that was not given rather than a colour that is
	 * empty, which is what a `null` means everywhere in {@see Stage}.
	 *
	 * The semantics travels as the value the storage boundary keeps it in; nothing else about the
	 * boundary crosses this line.
	 *
	 * @return array<string, mixed>
	 */
	protected static function stageFieldsToWrite(Stage $stage): array
	{
		$values = [
			Stage::name => $stage->getName(),
			Stage::color => $stage->getColor(),
			Stage::semantics => $stage->getSemantics()?->internalToStorageValue(),
			Stage::sort => $stage->getSort(),
			Stage::isSystem => $stage->getIsSystem(),
		];

		return self::valuesOfChangedFields($values, $stage->getChangedFieldNames());
	}

	/**
	 * The answer of a write of a stage - {@see self::applyWriteResult()} for a stage, reporting the
	 * success through the stage it was given: the identifier a new one received, the fields the write
	 * did not mention, and no change marks left over.
	 */
	protected static function applyStageWriteResult(Stage $stage, Result $result): Result
	{
		if (!$result->isSuccess())
		{
			return $result;
		}

		self::fillStage($stage, $result);

		return new Result();
	}

	/**
	 * The answer of a write of the whole stage set of a category: an empty successful {@see Result},
	 * or the refusal as it came.
	 *
	 * A group replacement answers with a set rather than with a stage, so the collection it was given
	 * is left holding the stages the category ended up with - in the order they stand in, the
	 * untouched final and system stages among them. The stages that went in are replaced rather than
	 * updated: which of them survived the replacement, and where, is what the answer is about.
	 */
	protected static function applyStageSetResult(StageCollection $stages, Result $result): Result
	{
		if (!$result->isSuccess())
		{
			return $result;
		}

		$replaced = [];
		foreach ($result->getData()[StageSetReplacer::DATA_KEY_STAGES] as $stored)
		{
			$stage = new Stage();
			self::fillStageFrom($stage, $stored);
			$replaced[] = $stage;
		}

		$stages->internalReplaceAll(...$replaced);

		return new Result();
	}

	/**
	 * Copies the stage storage now holds into the stage the caller handed in.
	 */
	private static function fillStage(Stage $stage, Result $result): void
	{
		self::fillStageFrom($stage, $result->getData()[StageRepositoryInterface::DATA_KEY_STAGE]);
	}

	/**
	 * Copies $stored into $stage.
	 *
	 * A stage without a stored colour gets an empty string rather than `null`, the way a stage read
	 * through {@see \Bitrix\Crm\V2\Public\Provider\Category\StageProvider} does: `null` is what a
	 * write reads as "leave the colour alone", and a stage that came back from storage has a colour.
	 */
	private static function fillStageFrom(Stage $stage, StageData $stored): void
	{
		$stage
			->setStageId($stored->stageId)
			->setCategoryId($stored->categoryId)
			->setName($stored->name)
			->setColor($stored->color ?? '')
			->setSemantics(StageSemantics::internalFromStorageValue($stored->semantics))
			->setSort($stored->sort)
			->setIsSystem($stored->isSystem)
		;
		$stage->resetChangedFields();
	}
}
