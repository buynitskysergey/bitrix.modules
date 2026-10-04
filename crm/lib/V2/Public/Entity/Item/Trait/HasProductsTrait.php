<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Trait;

use Bitrix\Crm\V2\Public\Entity\Item\Currency;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;

/**
 * Product-related fields: opportunity, taxes, currency, product rows.
 */
trait HasProductsTrait
{
	private ?float $opportunity = null;
	private ?bool $isManualOpportunity = null;
	private ?float $taxValue = null;
	private ?string $currencyId = null;
	private ?Currency $currency = null;
	private ?float $exchRate = null;
	private ?float $opportunityAccount = null;
	private ?float $taxValueAccount = null;
	private ?string $accountCurrencyId = null;
	private ?ProductRowCollection $productRows = null;

	public function getOpportunity(): ?float
	{
		return $this->opportunity;
	}

	public function setOpportunity(?float $opportunity): static
	{
		$this->opportunity = $opportunity;
		$this->markChanged(self::opportunity);

		return $this;
	}

	public function getIsManualOpportunity(): ?bool
	{
		return $this->isManualOpportunity;
	}

	public function setIsManualOpportunity(?bool $isManualOpportunity): static
	{
		$this->isManualOpportunity = $isManualOpportunity;
		$this->markChanged(self::isManualOpportunity);

		return $this;
	}

	public function getTaxValue(): ?float
	{
		return $this->taxValue;
	}

	public function setTaxValue(?float $taxValue): static
	{
		$this->taxValue = $taxValue;
		$this->markChanged(self::taxValue);

		return $this;
	}

	public function getCurrencyId(): ?string
	{
		return $this->currencyId;
	}

	public function setCurrencyId(?string $currencyId): static
	{
		$this->currencyId = $currencyId;
		$this->markChanged(self::currencyId);

		return $this;
	}

	public function getCurrency(): ?Currency
	{
		return $this->currency;
	}

	public function getExchRate(): ?float
	{
		return $this->exchRate;
	}

	public function setExchRate(?float $exchRate): static
	{
		$this->exchRate = $exchRate;
		$this->markChanged(self::exchRate);

		return $this;
	}

	public function getOpportunityAccount(): ?float
	{
		return $this->opportunityAccount;
	}

	public function setOpportunityAccount(?float $opportunityAccount): static
	{
		$this->opportunityAccount = $opportunityAccount;
		$this->markChanged(self::opportunityAccount);

		return $this;
	}

	public function getTaxValueAccount(): ?float
	{
		return $this->taxValueAccount;
	}

	public function setTaxValueAccount(?float $taxValueAccount): static
	{
		$this->taxValueAccount = $taxValueAccount;
		$this->markChanged(self::taxValueAccount);

		return $this;
	}

	public function getAccountCurrencyId(): ?string
	{
		return $this->accountCurrencyId;
	}

	public function setAccountCurrencyId(?string $accountCurrencyId): static
	{
		$this->accountCurrencyId = $accountCurrencyId;
		$this->markChanged(self::accountCurrencyId);

		return $this;
	}

	public function getProductRows(): ?ProductRowCollection
	{
		return $this->productRows;
	}

	public function setProductRows(ProductRowCollection $productRows): static
	{
		$this->productRows = $productRows;
		$this->markChanged(self::productRows);

		return $this;
	}

	protected function internalSetProductField(string $fieldName, mixed $value): bool
	{
		$success = true;
		match ($fieldName)
		{
			self::opportunity => $this->opportunity = $value,
			self::isManualOpportunity => $this->isManualOpportunity = $value,
			self::taxValue => $this->taxValue = $value,
			self::currencyId => $this->currencyId = $value,
			'currency' => $this->currency = $value,
			self::exchRate => $this->exchRate = $value,
			self::opportunityAccount => $this->opportunityAccount = $value,
			self::taxValueAccount => $this->taxValueAccount = $value,
			self::accountCurrencyId => $this->accountCurrencyId = $value,
			self::productRows => $this->productRows = $value,
			default => $success = false,
		};

		return $success;
	}
}
