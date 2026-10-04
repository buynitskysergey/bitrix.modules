<?php
declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Main\Entity\EntityInterface;

final class CompanyBinding implements EntityInterface
{
	public function __construct(
		private readonly int $companyId,
		private readonly int $sort = 0,
		private readonly bool $isPrimary = false,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->companyId;
	}

	public function getCompanyId(): int
	{
		return $this->companyId;
	}

	public function getSort(): int
	{
		return $this->sort;
	}

	public function isPrimary(): bool
	{
		return $this->isPrimary;
	}
}
