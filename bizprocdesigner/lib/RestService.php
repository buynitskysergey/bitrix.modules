<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner;

use Bitrix\Main\Loader;

Loader::includeModule('rest');

class RestService extends \IRestService
{
	public const SCOPE = 'bizprocdesigner';

	public static function onRestServiceBuildDescription(): array
	{
		return [self::SCOPE => []];
	}
}
