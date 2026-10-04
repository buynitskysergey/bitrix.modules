<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldData;

use Bitrix\Rest\V3\Dto\Dto;

class MoneyExtraDto extends Dto
{
	public ?float $amount = null;
	public ?string $currencyId = null;
	public ?string $currencyFullName = null;
}
