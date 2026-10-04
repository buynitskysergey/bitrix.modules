<?php

namespace Bitrix\SalesCenter\Component;

use Bitrix\Catalog\VatTable;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class VatRate
{
	/**
	 * Prepare tax prices for sale basket item (price saves without vat).
	 *
	 * @param array $basketItems in format for `BasketItem`
	 *
	 * @return array
	 *
	 * @see \Bitrix\Catalog\v2\Integration\JS\ProductForm\BasketItem
	 */
	public static function prepareTaxPrices(array $basketItems): array
	{
		Loader::requireModule('sale');
		Loader::requireModule('catalog');

		foreach ($basketItems as & $item)
		{
			if (isset($item['taxId']))
			{
				$vatRateRow = VatTable::getRowById((int)$item['taxId']);
				if (!$vatRateRow)
				{
					continue;
				}

				$vatRate = isset($vatRateRow['RATE']) ? (float)$vatRateRow['RATE'] : null;
				$item['taxRate'] = $vatRate;
				if ($vatRate > 0 && isset($item['price']))
				{
					$inputFactory = ServiceLocator::getInstance()->get('sale.basketItemInputFactory');
					$vatCalculator = ServiceLocator::getInstance()->get('sale.vatCalculator');

					$isTaxExcluded = ($item['taxIncluded'] ?? 'Y') === 'N';

					$input = $inputFactory->createFromArray([
						'basePrice' => (float)$item['price'],
						'vatRate' => $vatRate,
						'vatIncluded' => $isTaxExcluded,
					]);
					$netPrice = $vatCalculator->allocateVat($input);
					$item['price'] = $netPrice;

					if ($isTaxExcluded)
					{
						// Canonicalize the netto slots from the single authoritative gross
						// `price` instead of trusting the incoming `priceExclusive`/`basePrice`.
						// For taxIncluded='N' catalog.product-form may forward a gross value in
						// a netto slot (the payment slider for a deal product — jabber #248006;
						// or a falsy netto field that falls back to the gross `price` in row.js
						// when the same product is repeated and one row is removed). Leaving it
						// makes the Order builder accrue VAT a second time (double VAT). The
						// recompute is a no-op for a well-formed netto payload, so the contract
						// for newly added products is preserved.
						if (isset($item['priceExclusive']))
						{
							$item['priceExclusive'] = $netPrice;
						}

						if (isset($item['basePrice']))
						{
							$item['basePrice'] = self::canonicalizeNetBasePrice(
								(float)$item['basePrice'],
								$netPrice,
								isset($item['discount']) ? max(0.0, (float)$item['discount']) : 0.0,
								$vatRate,
								$vatCalculator,
								$inputFactory
							);
						}
					}
				}
			}
		}

		return $basketItems;
	}

	/**
	 * Return the base price in netto coordinates for a taxIncluded='N' item.
	 *
	 * The base price must be netto (pre-discount). catalog.product-form can forward
	 * a gross base price when a falsy netto field falls back to the gross `price`
	 * (see row.js). The gross case is detected deterministically by matching the
	 * incoming value against the two possible coordinate interpretations — netto
	 * (`$netPrice` + net discount) and its accrued gross twin — within display
	 * (2-decimal) rounding tolerance, and corrected only then. A well-formed netto
	 * base price, or a value that cannot be classified, is returned untouched, so
	 * no calculation quality is lost for correct payloads.
	 *
	 * @param float $basePrice incoming base price (possibly gross)
	 * @param float $netPrice netto price accrued from the authoritative gross `price`
	 * @param float $netDiscount per-unit net discount (0 when absent)
	 * @param float $vatRate VAT rate in percent
	 * @param \Bitrix\Sale\Public\Contract\VatCalculatorInterface $vatCalculator
	 * @param \Bitrix\Sale\Public\Factory\BasketItemInputFactory $inputFactory
	 */
	private static function canonicalizeNetBasePrice(
		float $basePrice,
		float $netPrice,
		float $netDiscount,
		float $vatRate,
		$vatCalculator,
		$inputFactory
	): float
	{
		$expectedNet = $netPrice + $netDiscount;
		$expectedGross = $vatCalculator->accrueVat($inputFactory->createFromArray([
			'basePrice' => $expectedNet,
			'vatRate' => $vatRate,
			'vatIncluded' => false,
		]));

		// Basket items travel between JS and PHP in display precision (2 decimals).
		$tolerance = 0.01;

		if (abs($basePrice - $expectedNet) <= $tolerance)
		{
			// Already netto — keep the incoming value byte-identical.
			return $basePrice;
		}

		if (abs($basePrice - $expectedGross) <= $tolerance)
		{
			// A gross base price leaked into the netto slot — correct it.
			return $expectedNet;
		}

		// Ambiguous — do not risk changing a value we cannot classify.
		return $basePrice;
	}

	/**
	 * Restore the GROSS display price for a taxIncluded='N' row that prepareTaxPrices
	 * has normalized to netto but that the refresh path cannot recompute from a built
	 * basket item.
	 *
	 * The frontend `price` slot is gross by contract (catalog.product-form row.js).
	 * prepareTaxPrices deliberately leaves `price` in netto for taxIncluded='N'
	 * (see ALG-01 / VatRateTest). That is fine while the row round-trips through a
	 * basket item, because Order::fillResultBasket overwrites `price` with the
	 * calculator's brutto. But when a repeated deal product is merged away by the CRM
	 * distributed-quantity builder, its row has no basket item to read brutto from and
	 * would be returned with `price` still in netto. The frontend then re-sends that
	 * netto value as `price`, and the next prepareTaxPrices re-applies allocateVat to
	 * it — a ÷(1 + rate) ratchet that makes the price shrink on every refresh.
	 *
	 * Re-accruing the gross once here restores the `price`=brutto input contract, so
	 * the round-trip is stable: prepareTaxPrices(brutto) → netto,
	 * restoreGrossDisplayPrice(netto) → the same brutto, indefinitely.
	 *
	 * No-op for taxIncluded='Y' (price already gross), a missing/zero rate, or a
	 * missing price/taxId — there is nothing to project.
	 *
	 * @param array $item basket item in `BasketItem` format, already normalized by prepareTaxPrices
	 *
	 * @return array
	 *
	 * @see \Bitrix\Catalog\v2\Integration\JS\ProductForm\BasketItem
	 */
	public static function restoreGrossDisplayPrice(array $item): array
	{
		if (($item['taxIncluded'] ?? 'Y') !== 'N' || !isset($item['price'], $item['taxId']))
		{
			return $item;
		}

		Loader::requireModule('sale');
		Loader::requireModule('catalog');

		$vatRateRow = VatTable::getRowById((int)$item['taxId']);
		$vatRate = ($vatRateRow && isset($vatRateRow['RATE'])) ? (float)$vatRateRow['RATE'] : 0.0;
		if ($vatRate <= 0)
		{
			return $item;
		}

		$inputFactory = ServiceLocator::getInstance()->get('sale.basketItemInputFactory');
		$vatCalculator = ServiceLocator::getInstance()->get('sale.vatCalculator');

		$item['price'] = $vatCalculator->accrueVat($inputFactory->createFromArray([
			'basePrice' => (float)$item['price'],
			'vatRate' => $vatRate,
			'vatIncluded' => false,
		]));

		return $item;
	}

	/**
	 * Calculated price with vat from basket item fields.
	 *
	 * @param array $basketFields
	 *
	 * @return float
	 */
	public static function getPriceWithTax(array $basketFields): float
	{
		Loader::requireModule('sale');

		$price = (float)($basketFields['PRICE'] ?? 0.0);
		$vatIncluded = ($basketFields['VAT_INCLUDED'] ?? 'Y') === 'Y';
		if (!$vatIncluded)
		{
			$vatRate = (float)($basketFields['VAT_RATE'] ?? 0.0);
			if ($vatRate > 0)
			{
				$inputFactory = ServiceLocator::getInstance()->get('sale.basketItemInputFactory');
				$input = $inputFactory->createFromArray([
					'basePrice' => $price,
					'vatRate' => $vatRate * 100,
					'vatIncluded' => false,
				]);
				$vatCalculator = ServiceLocator::getInstance()->get('sale.vatCalculator');
				$price = $vatCalculator->accrueVat($input);
			}
		}

		return $price;
	}
}
