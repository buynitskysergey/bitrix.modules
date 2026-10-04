<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Category;

use Bitrix\Main\Entity\EntityInterface;

/**
 * A stage of a category (pipeline): the input a command of this domain is given and the answer a
 * provider of it returns. A pure data object - it neither reads nor writes anything.
 *
 * `stageId` is the whole identifier of the stage, already namespaced by category where the entity
 * type namespaces it: `C2:NEW` for a Deal category, `DT128_5:NEW` for a smart process one. A stage
 * that does not exist yet has none.
 *
 * `stageId` and `categoryId` address the stage rather than describe it: setting either says which
 * stage is meant, never that it should change. Every other setter marks its field as changed, and a
 * write carries the fields that were set and only them, so a field left alone keeps its stored value
 * and a field the domain does not write is refused ({@see CategoryError::FIELD_NOT_WRITABLE}) rather
 * than silently dropped. A field set to `null` is a field that was not given rather than one that is
 * empty: it stays out of the write too, so the stored value is kept and the field is not refused for
 * being unwritable either. That is what a `null` means throughout this domain ({@see Category}).
 *
 * The colour is where it is met most: a `null` there is the absence of a colour rather than a value,
 * and on a write it leaves the stored colour of the stage alone. A stage that comes from the domain
 * always carries a colour, an empty string where none is stored.
 *
 * The methods marked `@internal` below carry the change marks over to the commands and the
 * providers of the domain. They are public because those live in other namespaces and PHP offers
 * nothing narrower, not because they are part of the contract - the one of them a consumer has a use
 * for is {@see self::hasChangedFields()}.
 */
final class Stage implements EntityInterface
{
	public const stageId = 'stageId';
	public const categoryId = 'categoryId';
	public const name = 'name';
	public const color = 'color';
	public const semantics = 'semantics';
	public const sort = 'sort';
	public const isSystem = 'isSystem';

	private array $changedFields = [];

	private ?string $stageId = null;
	private ?int $categoryId = null;
	private ?string $name = null;
	private ?string $color = null;
	private ?StageSemantics $semantics = null;
	private ?int $sort = null;
	private ?bool $isSystem = null;

	/**
	 * The identity of a stage is its whole identifier; {@see self::getStageId()} is the domain name
	 * of the same value.
	 */
	public function getId(): ?string
	{
		return $this->stageId;
	}

	public function getStageId(): ?string
	{
		return $this->stageId;
	}

	public function setStageId(?string $stageId): self
	{
		$this->stageId = $stageId;

		return $this;
	}

	/**
	 * The virtual category of Deal is `0` rather than a missing value.
	 */
	public function getCategoryId(): ?int
	{
		return $this->categoryId;
	}

	public function setCategoryId(?int $categoryId): self
	{
		$this->categoryId = $categoryId;

		return $this;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(?string $name): self
	{
		$this->name = $name;
		$this->markChanged(self::name);

		return $this;
	}

	public function getColor(): ?string
	{
		return $this->color;
	}

	public function setColor(?string $color): self
	{
		$this->color = $color;
		$this->markChanged(self::color);

		return $this;
	}

	public function getSemantics(): ?StageSemantics
	{
		return $this->semantics;
	}

	public function setSemantics(?StageSemantics $semantics): self
	{
		$this->semantics = $semantics;
		$this->markChanged(self::semantics);

		return $this;
	}

	public function getSort(): ?int
	{
		return $this->sort;
	}

	public function setSort(?int $sort): self
	{
		$this->sort = $sort;
		$this->markChanged(self::sort);

		return $this;
	}

	public function getIsSystem(): ?bool
	{
		return $this->isSystem;
	}

	public function setIsSystem(?bool $isSystem): self
	{
		$this->isSystem = $isSystem;
		$this->markChanged(self::isSystem);

		return $this;
	}

	/**
	 * @internal The fields a write is to carry.
	 *
	 * @return string[]
	 */
	public function getChangedFieldNames(): array
	{
		return array_keys($this->changedFields);
	}

	/**
	 * @internal Whether a write of this stage would carry anything at all.
	 */
	public function hasChangedFields(): bool
	{
		return $this->changedFields !== [];
	}

	/**
	 * @internal Used by a provider once it has filled a stage it read, and by a command once the
	 * write it reports has gone through: a stage that comes from the domain carries no changes of its
	 * own.
	 */
	public function resetChangedFields(): void
	{
		$this->changedFields = [];
	}

	private function markChanged(string $fieldName): void
	{
		$this->changedFields[$fieldName] = true;
	}
}
