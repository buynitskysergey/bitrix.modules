<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public;

final class ItemId implements \JsonSerializable
{
	private ?int $categoryId;

	public function __construct(
		private readonly EntityType $entityType,
		private readonly int $id,
		?int $categoryId = null,
	)
	{
		if ($id <= 0)
		{
			throw new \Bitrix\Main\ArgumentException("Item ID must be positive, got: {$id}");
		}
		$this->categoryId = $categoryId;
	}

	public function getEntityType(): EntityType
	{
		return $this->entityType;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getCategoryId(): ?int
	{
		if ($this->categoryId === null)
		{
			$this->categoryId = $this->loadCategoryId();
		}

		return $this->categoryId;
	}

	private function loadCategoryId(): ?int
	{
		if (!EntityTypeSettings::of($this->entityType)->hasCategories())
		{
			return null;
		}

		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($this->entityType->getId());

		return $factory ? (int)$factory->getItemCategoryId($this->id) : null;
	}

	public function getHash(): string
	{
		return 'type_' . $this->entityType->getId() . '_id_' . $this->id;
	}

	public function equals(self $other): bool
	{
		return $this->entityType->equals($other->entityType) && $this->id === $other->id;
	}

	public function jsonSerialize(): array
	{
		return [
			'entityTypeId' => $this->entityType->getId(),
			'entityId' => $this->id,
			'categoryId' => $this->getCategoryId(),
		];
	}

}
