<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

interface ValidValuesProviderInterface
{
	public static function getValidValues(Context $context): array;
}
