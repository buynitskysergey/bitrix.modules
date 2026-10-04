<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Interface;

use Bitrix\Crm\V2\Public\Entity\Item\Category;

interface HasCategoriesInterface
{
	public function getCategoryId(): ?int;
	public function setCategoryId(?int $categoryId): static;
	public function getCategory(): ?Category;
}
