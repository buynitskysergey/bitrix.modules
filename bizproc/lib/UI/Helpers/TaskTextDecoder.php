<?php

namespace Bitrix\Bizproc\UI\Helpers;

/**
 * Task PARAMETERS read via CBPTaskService::GetList() come escaped: CDBResult::GetNext()
 * runs htmlspecialcharsEx() over the array unserialized by CBPTaskResult::fetch().
 */
class TaskTextDecoder
{
	public static function decode(mixed $value, string $default = ''): string
	{
		if (\CBPHelper::isEmptyValue($value))
		{
			return $default;
		}

		return html_entity_decode(htmlspecialcharsback(\CBPHelper::stringify($value)));
	}
}
