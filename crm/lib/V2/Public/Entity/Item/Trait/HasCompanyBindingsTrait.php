<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Trait;

use Bitrix\Crm\V2\Public\Entity\Item\CompanyBindingCollection;

/**
 * Company binding field.
 */
trait HasCompanyBindingsTrait
{
	private ?CompanyBindingCollection $companyBindings = null;

	public function getCompanyBindings(): ?CompanyBindingCollection
	{
		return $this->companyBindings;
	}

	public function setCompanyBindings(CompanyBindingCollection $companyBindings): static
	{
		$this->companyBindings = $companyBindings;
		$this->markChanged(self::companyBindings);

		return $this;
	}

	protected function internalSetCompanyBindingField(string $fieldName, mixed $value): bool
	{
		if ($fieldName === self::companyBindings)
		{
			$this->companyBindings = $value;

			return true;
		}

		return false;
	}
}
