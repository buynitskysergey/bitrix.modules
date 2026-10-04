<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity;

/**
 * The output package of an activity in the wire shape the editor expects: a list whose every entry carries
 * its own `Id`.
 *
 * The package itself is composed by {@see \CBPRuntime::getActivityReturnProperties()} - the `RETURN` of the
 * activity description plus the properties an `ADDITIONAL_RESULT` marker points at inside the instance's own
 * `Properties` (a trigger's `Return`, a unified node's `FilterReturnPropertiesMap`). This class only turns
 * that id-keyed map into the list every transport publishes, so that template load, activity save and the
 * node catalog answer with one and the same package instead of each keeping its own copy of the conversion.
 */
final class ReturnPropertiesResolver
{
	/**
	 * @param array|string $activityOrCode Full activity instance (`Type` + `Properties`) or a bare code.
	 *   A bare code yields the declared `RETURN` only: `ADDITIONAL_RESULT` is expanded from the instance.
	 * @return list<array> Properties of the package, each with its `Id` filled in.
	 */
	public static function resolve(array|string $activityOrCode): array
	{
		$properties = \CBPRuntime::getRuntime()->getActivityReturnProperties($activityOrCode);
		foreach ($properties as $id => &$property)
		{
			$property['Id'] = $id;
		}
		unset($property);

		return array_values($properties);
	}
}
