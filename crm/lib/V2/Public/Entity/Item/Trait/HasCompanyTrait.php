<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Trait;

use Bitrix\Crm\V2\Public\Entity\Item\Company;

/**
 * Primary company reference (scalar int).
 * For entities with a single client-company relationship: Deal, Lead, Quote, Smart*, SmartProcess, Contact.
 * Mapped to legacy COMPANY_ID.
 */
trait HasCompanyTrait
{
	private ?int $companyId = null;
	private ?Company $company = null;

	public function getCompanyId(): ?int
	{
		return $this->companyId;
	}

	public function setCompanyId(?int $companyId): static
	{
		$this->companyId = $companyId;
		$this->markChanged(self::companyId);

		return $this;
	}

	public function getCompany(): ?Company
	{
		return $this->company;
	}

	protected function internalSetCompanyField(string $fieldName, mixed $value): bool
	{
		if ($fieldName === self::companyId)
		{
			$this->companyId = $value;

			return true;
		}
		if ($fieldName === 'company')
		{
			$this->company = $value;

			return true;
		}

		return false;
	}
}
