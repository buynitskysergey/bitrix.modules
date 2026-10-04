<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

/**
 * A dictionary entry of the category field of an item: the identifier the item is filed under and
 * the caption that category is shown by. It describes a field of an item and nothing else - it is
 * read off an item, never configured through one.
 *
 * The category (pipeline) as the domain a caller adds, renames and deletes one in is
 * {@see \Bitrix\Crm\V2\Public\Entity\Category\Category}. Two types rather than one because the two
 * are not the same object: this one is immutable, always knows its identifier and needs no entity
 * type, while a category of that domain is mutable, tracks which of its fields a write is to carry,
 * and is addressed by entity type and identifier together - a category being added has no
 * identifier yet. Nor could either be made a facade over the other: PHP does not let a mutable class
 * extend a readonly one, and the other way round would put a required identifier in a constructor
 * that must do without one.
 */
final readonly class Category
{
	public function __construct(
		private int $id,
		private ?string $name = null,
		private ?int $sort = null,
		private ?bool $isDefault = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function getSort(): ?int
	{
		return $this->sort;
	}

	public function getIsDefault(): ?bool
	{
		return $this->isDefault;
	}
}
