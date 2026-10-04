<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Field;

use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;

class GetRequest extends ListRequest
{
	public string $name;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		if (!is_string($httpRequest->getJsonList()->getRaw('name')))
		{
			throw new InvalidRequestFieldTypeException('name', 'string');
		}

		return $request;
	}
}
