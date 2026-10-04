<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowErrorCode;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * `crm.{entity}.productRow.update`. Only the fields the client sent are applied; a filter is refused by
 * {@see AbstractProductRowIdRequest}.
 *
 * `select` has the meaning it has in {@see GetProductRowRequest}, and it is here because the answer of
 * this method is the row itself: normalization decides what a written value became, and a client that
 * cannot ask for the result would have to read the row again to learn it.
 */
final class UpdateProductRowRequest extends AbstractProductRowIdRequest
{
	public FieldsStructure $fields;

	public ?SelectStructure $select = null;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);

		// An empty object is a question about the form of the request; whether anything survives the list
		// of writable fields is a question for the scenario. Written out because #[NotEmpty] cannot make
		// the check: the fields structure is an object and not countable, so the rule sees it as filled.
		//
		// The wording is the scenario's, not this class's: a write given nothing to perform is refused
		// here or there depending only on whether a field survived the whitelist, and a client must not
		// learn from the answer which of the two refused it.
		if ($request->fields->getItems() === [])
		{
			throw new RequestValidationException([
				new Error(ProductRowErrorCode::getEmptyFieldsMessage(), 'fields'),
			]);
		}

		return $request;
	}
}
