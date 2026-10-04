<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Category;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * The closed set of refusals the category domain reports.
 *
 * The case name is the code that leaves the domain: it travels as {@see Error::getCode()}, and a
 * consumer recovers it with `CategoryError::tryFrom($error->getCode())`. Every refusal is one of
 * these - neither an exception of the storage boundary nor an error text of its own ever reaches
 * a caller.
 *
 * `CATEGORY_NOT_FOUND` is also the answer when the category exists but the user may not read it:
 * a missing read right must not reveal that the category is there.
 *
 * `FIELD_NOT_WRITABLE` and `FIELD_VALUE_NOT_ALLOWED` are the two ways a field of a write is refused,
 * and both name it in `['field' => ...]` of {@see Error::getCustomData()}: the first says the field
 * is not written at all, the second that the value given to it is not one the domain accepts - a
 * name of nothing but blanks, which would otherwise be dropped on the way and answered with success.
 *
 * `OPERATION_NOT_ALLOWED_FOR_CATEGORY` and `OPERATION_NOT_ALLOWED_FOR_STAGE` are each a whole class
 * of refusals rather than one rule: a system stage, the last success stage of a category, an entity
 * type whose stages do not live in categories and the order the semantics of a set has to keep all
 * answer the stage one. Which of them it was is in the message and not in the code, so a consumer
 * that has to tell them apart cannot.
 */
enum CategoryError: string
{
	case CATEGORY_NOT_FOUND = 'CATEGORY_NOT_FOUND';
	case STAGE_NOT_FOUND = 'STAGE_NOT_FOUND';
	case DEPENDENT_ITEMS_EXIST = 'DEPENDENT_ITEMS_EXIST';
	case OPERATION_NOT_ALLOWED_FOR_CATEGORY = 'OPERATION_NOT_ALLOWED_FOR_CATEGORY';
	case OPERATION_NOT_ALLOWED_FOR_STAGE = 'OPERATION_NOT_ALLOWED_FOR_STAGE';
	case TARIFF_LIMIT_EXCEEDED = 'TARIFF_LIMIT_EXCEEDED';
	case FIELD_NOT_WRITABLE = 'FIELD_NOT_WRITABLE';
	case FIELD_VALUE_NOT_ALLOWED = 'FIELD_VALUE_NOT_ALLOWED';
	case CATEGORY_OPERATION_FAILED = 'CATEGORY_OPERATION_FAILED';

	public function getMessage(): string
	{
		Loc::loadMessages(__FILE__);

		return (string)Loc::getMessage('CRM_V2_CATEGORY_ERROR_' . $this->value);
	}

	public function toError(): Error
	{
		return new Error($this->getMessage(), $this->value);
	}
}
