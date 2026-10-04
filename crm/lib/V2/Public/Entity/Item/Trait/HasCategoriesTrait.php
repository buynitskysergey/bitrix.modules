<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Trait;

use Bitrix\Crm\V2\Public\Entity\Item\Category;

trait HasCategoriesTrait
{
	private ?int $categoryId = null;
	private ?Category $category = null;

	public function getCategoryId(): ?int
	{
		return $this->categoryId;
	}

	public function setCategoryId(?int $categoryId): static
	{
		$this->categoryId = $categoryId;
		$this->markChanged(self::categoryId);

		return $this;
	}

	public function getCategory(): ?Category
	{
		return $this->category;
	}

	protected function internalSetCategoryField(string $fieldName, mixed $value): bool
	{
		if ($fieldName === self::categoryId)
		{
			$this->categoryId = $value;

			return true;
		}
		if ($fieldName === 'category')
		{
			$this->category = $value;

			return true;
		}

		return false;
	}
}
