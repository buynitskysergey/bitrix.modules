<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule;

use Attribute;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\Context;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Rest\Exceptions\ArgumentTypeException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ElementsItemIdentifier extends ItemIdentifier
{
	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		$result = new ValidationResult();
		$failedValidator = null;

		if (!is_iterable($propertyValue))
		{
			throw new ArgumentTypeException('propertyValue', 'iterable');
		}

		if (!isset($this->context))
		{
			$this->context = $this->buildContext($propertyValue);
		}

		foreach ($propertyValue as $value)
		{
			$valueResult = parent::validateProperty($value);
			$result->addErrors($valueResult->getErrors());
			$failedValidator ??= $this->getFailedValidator($valueResult);
		}

		return $this->replaceWithCustomError($result, $failedValidator);
	}
}
