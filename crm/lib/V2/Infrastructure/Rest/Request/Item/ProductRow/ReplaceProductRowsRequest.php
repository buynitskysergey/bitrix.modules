<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestListLimit;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.{entity}.productRow.replace` - the whole set of a parent, replaced by the one given.
 *
 * The shape is the one an action of this kind has for any resource: the parent, and the collection that is
 * to become its content. Nothing here knows what a product row is - the element type comes from the DTO of
 * the route - so the same two properties describe the replacement of any nested collection, and only the
 * name of the class is of this subject.
 *
 * ### An absent set and an empty one are not the same
 *
 * `items` is typed and has no default, so a request that carries none is refused by
 * {@see Request::create()} for a missing property, while `items: []` reaches the scenario and clears the
 * composition - the ordinary way to empty it. The distinction has to be drawn this way round: `#[NotEmpty]`
 * would refuse the empty set as well, and on a structure it would not even fire.
 *
 * From above the set is bounded by {@see RequestListLimit}, the bound every list of a request of this
 * group shares: the cost of a replacement is the number of rows it carries, and a set over the bound is
 * refused rather than cut down to it. The refusal is one of the body of the request, which is what `items`
 * is.
 *
 * Rows are passed on as they were sent, keys included. A row identifier among the fields is **not** removed
 * here: the scenario refuses it and names the row it came from
 * ({@see \Bitrix\Crm\V2\Public\Command\Item\ProductRow\ReplaceCommand}), and a row silently stripped of a
 * field the client believed in would be a worse answer than a refusal.
 *
 * ### Why a replacement takes a `select`
 *
 * The answer is the resulting set, and the identifiers in it are the point: a row that changed is stored
 * anew under a new identifier, so the set the owner ends up with is not the set that was sent. `select`
 * has the meaning it has in {@see GetProductRowRequest} and decides how much of each resulting row is
 * answered.
 */
final class ReplaceProductRowsRequest extends Request
{
	private const ITEMS_FIELD = 'items';

	#[NotEmpty]
	#[PositiveNumber]
	public int $ownerId;

	public ?SelectStructure $select = null;

	/** @var array<int|string, array<string, mixed>> An empty set clears the composition of the owner. */
	public array $items;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);

		RequestListLimit::check($request->items, self::ITEMS_FIELD, RequestValidationException::class);

		foreach ($request->items as $key => $item)
		{
			if (!is_array($item))
			{
				throw new InvalidRequestFieldTypeException(self::ITEMS_FIELD . '.' . $key, $dtoClass);
			}
		}

		return $request;
	}
}
