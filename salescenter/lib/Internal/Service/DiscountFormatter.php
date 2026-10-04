<?php

declare(strict_types=1);

namespace Bitrix\Salescenter\Internal\Service;

use Bitrix\Main\DI\ServiceLocator;

/**
 * Converts sale-native "gross" discount amounts into CRM-native "net" ones.
 *
 * Sale stores DISCOUNT_PRICE in gross coordinates (part of BASE_PRICE when
 * VAT_INCLUDED=Y). CRM / catalog.product-form forms work in net (part of
 * PRICE_NETTO). Relationship: discountGross = discountNet * (1 + vatRate).
 *
 * The boundary conversion is applied only on the response to the UI. On save
 * the form sends `basePrice` and `price` in their tax-included coordinates
 * (gross when `taxIncluded=Y`, net when `taxIncluded=N`); gross DISCOUNT_PRICE
 * is then derived in {@see \Bitrix\Sale\Helpers\Order\Builder\Converter\CatalogJSProductForm::obtainProductFields()}
 * as `BASE_PRICE - PRICE` via `resolvePriceInBaseCoords()`, so this formatter
 * never has to round-trip the value back to gross.
 *
 * Delegates the gross→net allocation to the canonical
 * {@see \Bitrix\Sale\Public\Service\VatCalculator} from sale to keep a single
 * source of truth for VAT math.
 */
final class DiscountFormatter
{
	/**
	 * @param float $discountGross Discount in sale (gross) coordinates.
	 * @param float $vatRatePercent VAT rate in percent (20 for 20%), not a decimal.
	 * @param bool $vatIncluded Whether the stored price already includes VAT.
	 */
	public static function grossToNet(float $discountGross, float $vatRatePercent, bool $vatIncluded): float
	{
		$serviceLocator = ServiceLocator::getInstance();

		$inputFactory = $serviceLocator->get('sale.basketItemInputFactory');
		$vatCalculator = $serviceLocator->get('sale.vatCalculator');

		$input = $inputFactory->createFromArray([
			'basePrice' => $discountGross,
			'vatRate' => $vatRatePercent,
			'vatIncluded' => $vatIncluded,
		]);

		return $vatCalculator->allocateVat($input);
	}
}
