<?php

namespace Bitrix\Crm\Counter;

use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use Bitrix\Main\Web\Uri;

class DealCounter extends EntityCounter
{
	/**
	 * @param int $typeID Type ID (see EntityCounterType).
	 * @param int $entityTypeID Entity Type ID (see \CCrmOwnerType).
	 * @param int $userID User ID.
	 * @throws Main\ArgumentOutOfRangeException
	 */
	public function __construct($typeID, $userID = 0, array $params = null)
	{
		parent::__construct(\CCrmOwnerType::Deal, $typeID, $userID, $params);
	}

	/**
	 * Get details page URL.
	 *
	 * @param string $url Base URL.
	 * @return string
	 */
	public function prepareDetailsPageUrl($url = '')
	{
		$urlParams = ['counter' => mb_strtolower($this->getTypeName()), 'clear_nav' => 'Y'];

		//We may ignore DEAL_CATEGORY_ID parameter - it will be supplied by the crm.deal.list component.
		self::externalizeExtras(array_diff_key($this->extras, ['DEAL_CATEGORY_ID' => true]), $urlParams);

		if ($url === '')
		{
			$url = $this->getEntityListPath();
		}

		return (string)(new Uri($url))->addParams($urlParams);
	}

	protected function getEntityListPath()
	{
		if ($this->getCategoryId() === -1)
		{
			// all categories view
			return Container::getInstance()->getRouter()->getItemListUrlInCurrentView(\CCrmOwnerType::Deal)->getUri();
		}

		return parent::getEntityListPath();
	}
}
