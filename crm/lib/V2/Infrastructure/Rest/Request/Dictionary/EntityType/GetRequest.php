<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary\EntityType;

use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

final class GetRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?SelectStructure $select = null;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		if (!EntityType::isValid($request->id))
		{
			throw new InvalidRequestFieldTypeException('id', 'supported CRM Item entity type ID');
		}

		return $request;
	}
}
