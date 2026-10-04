<?php

namespace Bitrix\Salescenter\Builder;

use Bitrix\Crm\Order\Builder\OrderBuilderCrm;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Sale\PaySystem\ApplePay;
use Bitrix\Sale\PaySystem\Manager;
use Bitrix\Sale\TradingPlatform\Landing\Landing;
use Bitrix\SalesCenter\Integration\LandingManager;

class OrderBuilder extends OrderBuilderCrm
{
	public function buildTradeBindings()
	{
		if (
			!isset($this->formData["TRADING_PLATFORM"])
			&& (!$this->order || $this->order->getId() === 0)
			&& LandingManager::getInstance()->isSiteExists()
		)
		{
			$connectedSiteId = LandingManager::getInstance()->getConnectedSiteId();
			$code = Landing::getCodeBySiteId($connectedSiteId);
			$platform = Landing::getInstanceByCode($code);

			$this->formData['TRADING_PLATFORM'] = $platform->getId();
		}

		return parent::buildTradeBindings();
	}

	public function buildPayments()
	{
		if (
			empty($this->formData["PAYMENT"])
			&& $this->needCreateDefaultPayment()
		)
		{
			$fields = ['SUM' => 0];

			$paySystem = $this->getDefaultPaySystem();
			if ($paySystem)
			{
				$fields['PAY_SYSTEM_ID'] = $paySystem['ID'];
				$fields['PAY_SYSTEM_NAME'] = $paySystem['NAME'];
			}

			$inputFactory = ServiceLocator::getInstance()->get('sale.basketItemInputFactory');
			$basketCalculator = ServiceLocator::getInstance()->get('sale.basketCalculator');

			$inputs = [];
			foreach ($this->formData['PRODUCT'] as $index => $item)
			{
				$quantity = (float)($item['QUANTITY'] ?? 0);
				$inputs[] = $inputFactory->createFromArray([
					'basePrice' => (float)($item['PRICE'] ?? 0),
					'quantity' => $quantity,
					'vatRate' => (float)($item['VAT_RATE'] ?? 0) * 100,
					'vatIncluded' => ($item['VAT_INCLUDED'] ?? 'N') === 'Y',
				]);

				$fields['PRODUCT'][] = [
					'BASKET_CODE' => $index,
					'QUANTITY' => $quantity,
				];
			}

			$fields['SUM'] += $basketCalculator->calculate($inputs)->totalPrice;

			$this->formData["PAYMENT"] = [$fields];
		}

		if (isset($this->formData["PAYMENT"]))
		{
			foreach ($this->formData["PAYMENT"] as &$item)
			{
				if (
					empty($item['ID'])
					&& !isset($item['PAY_SYSTEM_ID'])
				)
				{
					$paySystem = $this->getDefaultPaySystem();
					if ($paySystem)
					{
						$item['PAY_SYSTEM_ID'] = $paySystem['ID'];
						$item['PAY_SYSTEM_NAME'] = $paySystem['NAME'];
					}
				}
			}
		}

		return parent::buildPayments();
	}

	protected function getDefaultPaySystem()
	{
		$paySystem = [];

		$paySystemList = Manager::getListWithRestrictionsByOrder($this->order);

		if ($this->isIMessageConnector())
		{
			foreach ($paySystemList as $item)
			{
				if (ApplePay::isApplePaySystem($item))
				{
					$paySystem = $item;
				}
			}
		}

		if (!$paySystem)
		{
			$paySystem = $this->findCashPaySystem($paySystemList);
			if (!$paySystem)
			{
				$paySystem = current($paySystemList);
			}
		}

		return $paySystem;
	}

	/**
	 * @param array $paySystemList
	 * @return array
	 */
	private function findCashPaySystem(array $paySystemList) : array
	{
		foreach ($paySystemList as $item)
		{
			if ($item['ACTION_FILE'] === 'cash')
			{
				return $item;
			}
		}

		return [];
	}

	/**
	 * @param array $paySystem
	 * @return bool
	 */
	private function isIMessageConnector(): bool
	{
		return isset($this->formData['CONNECTOR']) && $this->formData['CONNECTOR'] === 'imessage';
	}
}
