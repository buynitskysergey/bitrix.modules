<?php

namespace Bitrix\Catalog\RestView;

use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Rest\Integration\View\Attributes;
use Bitrix\Rest\Integration\View\DataType;
use Bitrix\Rest\Integration\View\Base;

final class DocumentElement extends Base
{
	/**
	 * Returns entity fields.
	 *
	 * @return array
	 */
	public function getFields()
	{
		$accessController = AccessController::getCurrent();
		$hasCompleteStoreAccess = $accessController->checkCompleteRight(ActionDictionary::ACTION_STORE_VIEW);
		$storeFieldAttributes = $hasCompleteStoreAccess
			? []
			: [Attributes::DISABLED_FILTER, Attributes::DISABLED_ORDER]
		;
		$purchasingPriceAttributes = (
			$hasCompleteStoreAccess
			&& $accessController->check(ActionDictionary::ACTION_PRODUCT_PURCHASE_INFO_VIEW)
		)
			? []
			: [Attributes::DISABLED_FILTER, Attributes::DISABLED_ORDER]
		;

		return [
			'ID'=>[
				'TYPE'=>DataType::TYPE_INT,
				'ATTRIBUTES'=>[
					Attributes::READONLY,
				]
			],
			'DOC_ID'=>[
				'TYPE'=>DataType::TYPE_INT,
				'ATTRIBUTES'=>[
					Attributes::IMMUTABLE,
				]
			],
			'STORE_FROM'=>[
				'TYPE'=>DataType::TYPE_INT,
				'ATTRIBUTES'=>$storeFieldAttributes,
			],
			'STORE_TO'=>[
				'TYPE'=>DataType::TYPE_INT,
				'ATTRIBUTES'=>$storeFieldAttributes,
			],
			'ELEMENT_ID'=>[
				'TYPE'=>DataType::TYPE_INT,
				'ATTRIBUTES'=>[
					Attributes::IMMUTABLE,
				]
			],
			'AMOUNT'=>[
				'TYPE'=>DataType::TYPE_FLOAT,
				'ATTRIBUTES'=>$storeFieldAttributes,
			],
			'PURCHASING_PRICE'=>[
				'TYPE'=>DataType::TYPE_FLOAT,
				'ATTRIBUTES'=>$purchasingPriceAttributes,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function internalizeArguments($name, $arguments): array
	{
		if ($name === 'fields')
		{
			return $arguments;
		}

		return parent::internalizeArguments($name, $arguments);
	}


	/**
	 * @inheritDoc
	 */
	public function externalizeResult($name, $fields): array
	{
		if ($name === 'fields')
		{
			return $fields;
		}

		return parent::externalizeResult($name, $fields);
	}
}
