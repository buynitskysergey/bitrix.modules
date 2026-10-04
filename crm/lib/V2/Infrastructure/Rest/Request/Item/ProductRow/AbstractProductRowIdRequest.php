<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * A request that addresses exactly one row, by identifier, and refuses to address rows by filter.
 *
 * The refusal is spelled out rather than left to the framework. {@see Request::create()} walks the
 * declared properties and reads nothing else, so a `filter` that no property declares is not rejected -
 * it is dropped without a word, and a client that meant to change a whole set is answered with the
 * success of a single row. The check therefore looks at the key the client actually sent.
 *
 * Two consequences of where it sits, both deliberate:
 *
 * - it runs after the parent, which is what decodes the body in the first place, so a request carrying a
 *   filter and no identifier is answered for the missing identifier; both answers are a
 *   {@see RequestValidationException} and differ in the message only;
 * - a `filter` that is not an array never reaches here: the transport keeps the key only for an array
 *   value when the route declares query parameters, and every route of this group declares one
 *   ({@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider}).
 *
 * {@see GetProductRowRequest} declares an identifier of its own instead of extending this class: reading
 * has no refusal to make, and its errors are the ones its contract lists.
 */
abstract class AbstractProductRowIdRequest extends Request
{
	private const FILTER_FIELD = 'filter';

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		$request = parent::create($httpRequest, $dtoClass, $options);

		if (array_key_exists(self::FILTER_FIELD, $httpRequest->getJsonList()->getValues()))
		{
			throw new RequestValidationException([
				new Error(
					'Filter is not supported here: the method addresses a single row by id.',
					self::FILTER_FIELD,
				),
			]);
		}

		return $request;
	}
}
