<?php

namespace Bitrix\Sign\Trait\Api;

use Bitrix\Main;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;
use Bitrix\Sign\Type;

/**
 * Reading of a batch response of the signing service. The shape of `results` belongs to the
 * sign <-> signsafe contract, so it is read in one place: a copy per service would let a change
 * of the contract be applied to one branch while the other silently rejects the answers.
 *
 * The using class provides the transport `$api` and the `$serializer` of the request.
 */
trait BatchRequestTrait
{
	/**
	 * Batch routes take no identifiers in the path, so the endpoint has no trailing slash: with one
	 * the service answers 404 `unknown_action`.
	 *
	 * @return Main\Result data holds `results` as Item\Api\Batch\ItemResult[] in the requested order
	 */
	private function requestBatch(string $endpoint, Contract\Item $request): Main\Result
	{
		$result = $this->api->post($endpoint, $this->serializer->serialize($request));
		if (!$result->isSuccess())
		{
			// a rejected request carries no item outcomes, whatever came with it
			return (new Main\Result())->addErrors($result->getErrors());
		}

		$items = $result->getData()['results'] ?? null;
		if (!is_array($items))
		{
			return (new Main\Result())->addError(new Main\Error(
				'Response: field `results` is absent',
				Type\Api\TransportErrorCode::INCORRECT_DATA->value,
			));
		}

		$results = [];
		foreach ($items as $item)
		{
			$results[] = Item\Api\Batch\ItemResult::createFromResponseItem((array)$item);
		}

		return (new Main\Result())->setData(['results' => $results]);
	}
}
