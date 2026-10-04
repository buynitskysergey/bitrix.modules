<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Provider\Item\CompanyProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Main\NotSupportedException;

class MyCompanyProvider implements ValidValuesProviderInterface
{
	/**
	 * @return int[]
	 */
	public static function getValidValues(Context $context): array
	{
		if ($context->isShowValues())
		{
			throw new NotSupportedException();
		}

		$ids = array_values(array_unique(array_filter(
			array_map('intval', (array)$context->getValue()),
			static fn(int $id): bool => $id > 0,
		)));
		if ($ids === [])
		{
			return [];
		}

		if (!Container::getInstance()->getUserPermissions($context->getUserId())->myCompany()->canReadBaseFields())
		{
			return [];
		}

		$collection = (new CompanyProvider())->getByIds($ids, new ItemSelect('isMyCompany'));
		$result = [];
		foreach ($collection as $company)
		{
			if ($company instanceof Company && $company->getIsMyCompany() === true && $company->getId() !== null)
			{
				$result[] = (int)$company->getId();
			}
		}

		return $result;
	}
}
