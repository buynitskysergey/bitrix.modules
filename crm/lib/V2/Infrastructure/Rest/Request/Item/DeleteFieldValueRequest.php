<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\NotEmpty;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\Request;

class DeleteFieldValueRequest extends AbstractItemRequest
{
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		$request = parent::create($httpRequest, $dtoClass, $options);

		if (!isset($request->value))
		{
			throw new RequiredFieldInRequestException('value');
		}

		return $request;
	}

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	#[NotEmpty]
	public string $fieldName;

	#[NotEmpty(allowZero: true)]
	public mixed $value;
}
