<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Trait;

use Bitrix\Crm\V2\Public\Entity\Item\Company;

trait HasMyCompanyTrait
{
	private ?int $myCompanyId = null;
	private ?Company $myCompany = null;

	public function getMyCompanyId(): ?int
	{
		return $this->myCompanyId;
	}

	public function setMyCompanyId(?int $myCompanyId): static
	{
		$this->myCompanyId = $myCompanyId;
		$this->markChanged(self::myCompanyId);

		return $this;
	}

	public function getMyCompany(): ?Company
	{
		return $this->myCompany;
	}

	protected function internalSetMyCompanyField(string $fieldName, mixed $value): bool
	{
		if ($fieldName === self::myCompanyId)
		{
			$this->myCompanyId = $value;

			return true;
		}
		if ($fieldName === 'myCompany')
		{
			$this->myCompany = $value;

			return true;
		}

		return false;
	}
}
