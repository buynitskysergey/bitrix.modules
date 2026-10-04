<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Category;

use Bitrix\Main\Entity\EntityInterface;

/**
 * A category (pipeline) of a CRM entity type: the input a command of this domain is given and the
 * answer a provider of it returns. A pure data object - it neither reads nor writes anything.
 *
 * The category an item stands in is a different type,
 * {@see \Bitrix\Crm\V2\Public\Entity\Item\Category} - a dictionary entry of a field of that item.
 * The doc block there says what tells the two apart and why neither can be made a facade over the
 * other; the short of it is that one is read off an item and the other is what a caller configuring
 * the pipelines of a portal works with.
 *
 * `id` and `entityTypeId` address the category rather than describe it: setting either says which
 * category is meant, never that it should change, and a write is never offered them. Every other
 * setter marks its field as changed, and a write carries the fields that were set and only them, so
 * a field left alone keeps its stored value and a field the entity type does not accept is refused
 * ({@see CategoryError::FIELD_NOT_WRITABLE}) rather than silently dropped. A field set to `null` is
 * a field that was not given rather than one that is empty: it stays out of the write too, so the
 * stored value is kept, which is what a `null` means throughout this domain ({@see Stage}).
 *
 * `isSystem` and `code` tell `null` from `false` and `''`: `null` is an entity type that has no such
 * attribute at all - a Deal category has neither - while `false` and `''` mean the attribute is
 * there and is not set.
 *
 * The methods marked `@internal` below carry the change marks over to the commands and the
 * providers of the domain. They are public because those live in other namespaces and PHP offers
 * nothing narrower, not because they are part of the contract - the one of them a consumer has a use
 * for is {@see self::hasChangedFields()}.
 */
final class Category implements EntityInterface
{
	public const id = 'id';
	public const entityTypeId = 'entityTypeId';
	public const name = 'name';
	public const sort = 'sort';
	public const isDefault = 'isDefault';
	public const isSystem = 'isSystem';
	public const code = 'code';

	private array $changedFields = [];

	private ?int $id = null;
	private ?int $entityTypeId = null;
	private ?string $name = null;
	private ?int $sort = null;
	private ?bool $isDefault = null;
	private ?bool $isSystem = null;
	private ?string $code = null;

	/**
	 * The default category of Deal is the virtual one, and its identifier is `0` rather than a
	 * missing value.
	 */
	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getEntityTypeId(): ?int
	{
		return $this->entityTypeId;
	}

	public function setEntityTypeId(?int $entityTypeId): self
	{
		$this->entityTypeId = $entityTypeId;

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

	public function getIsDefault(): ?bool
	{
		return $this->isDefault;
	}

	public function setIsDefault(?bool $isDefault): self
	{
		$this->isDefault = $isDefault;
		$this->markChanged(self::isDefault);

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

	public function getCode(): ?string
	{
		return $this->code;
	}

	public function setCode(?string $code): self
	{
		$this->code = $code;
		$this->markChanged(self::code);

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
	 * @internal Whether a write of this category would carry anything at all.
	 */
	public function hasChangedFields(): bool
	{
		return $this->changedFields !== [];
	}

	/**
	 * @internal Used by a provider once it has filled a category it read, and by a command once the
	 * write it reports has gone through: a category that comes from the domain carries no changes of
	 * its own.
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
