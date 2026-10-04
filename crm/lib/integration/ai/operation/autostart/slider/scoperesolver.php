<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\Slider;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ScopeResolver
{
	public function resolve(int $entityTypeId, ?int $categoryId): Result
	{
		$result = new Result();
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return $result->addError(new Error('invalid scope', 'invalid_scope'));
		}

		if (!$factory->isCategoriesSupported())
		{
			$result->setData([
				'entityTypeId' => $entityTypeId,
				'categoryId' => null,
				'categoryName' => null,
			]);

			return $result;
		}

		$category = $categoryId !== null
			? $factory->getCategory($categoryId)
			: $factory->getDefaultCategory()
		;
		if ($category === null)
		{
			return $result->addError(new Error('invalid scope', 'invalid_scope'));
		}

		$result->setData([
			'entityTypeId' => $entityTypeId,
			'categoryId' => $category->getId(),
			'categoryName' => $category->getName(),
		]);

		return $result;
	}
}
