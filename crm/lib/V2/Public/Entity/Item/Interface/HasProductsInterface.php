<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Interface;

use Bitrix\Crm\V2\Public\Entity\Item\Currency;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;

interface HasProductsInterface
{
	public function getOpportunity(): ?float;

	public function setOpportunity(?float $opportunity): static;

	public function getIsManualOpportunity(): ?bool;

	public function setIsManualOpportunity(?bool $isManualOpportunity): static;

	public function getTaxValue(): ?float;

	public function setTaxValue(?float $taxValue): static;

	public function getCurrencyId(): ?string;

	public function setCurrencyId(?string $currencyId): static;

	public function getCurrency(): ?Currency;

	public function getProductRows(): ?ProductRowCollection;

	public function setProductRows(ProductRowCollection $productRows): static;
}
