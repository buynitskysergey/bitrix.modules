<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * The fields a read answers with, and the single place that rule is stated.
 *
 * Three answers in one order: what the client asked for, the fields its scope is restricted to when it
 * asked for nothing, and one named field when neither applies. An empty asked-for list is nothing asked
 * for rather than everything, and is answered as one that carried no list at all. The list is never
 * empty, so a read is never asked for "everything" by accident.
 *
 * The rule belongs to the REST of the CRM rather than to any one group of methods: it decides the
 * composition of every response built field by field, and two copies of it would mean one group quietly
 * answering differently from another. Only the fallback is the group's own - the field it answers with
 * when nothing was asked for and no scope narrows the answer.
 *
 * That reading of an empty list holds while the DTOs answered here carry no user fields and no relation
 * fields: {@see SelectStructure::getList()} carries the scalar fields of the top level alone, so a client
 * asking for those alone would be answered the fallback instead of what it asked for. No consumer has such
 * fields today; the first one that does has to widen the emptiness test here rather than work around it.
 *
 * @see SelectStructure the structure the asked-for list comes in.
 */
final class RequestSelectedFields
{
	/**
	 * @param string $defaultFieldName the field a read falls back to; the identifier of the entity, as
	 *        the group that answers names it.
	 * @return string[] field names, never empty.
	 */
	public static function resolve(Request $request, string $defaultFieldName): array
	{
		$select = $request->select ?? null;
		$askedFor = $select?->getList() ?? [];
		if ($askedFor !== [])
		{
			return $askedFor;
		}

		$scope = $request->getOptions()['scope'] ?? null;
		$scopeFields = $scope?->fields ?? [];

		return $scopeFields === [] ? [$defaultFieldName] : $scopeFields;
	}
}
