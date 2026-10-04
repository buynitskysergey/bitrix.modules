<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\CurrencyIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Min;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

class MoneyDto extends Dto
{
	#[Editable]
	#[Required(['add', 'update'])]
	#[Min(0)]
	public float $sum;

	#[Editable]
	#[Required(['add', 'update'])]
	#[InArrayFrom(CurrencyIdProvider::class, showValues: true)]
	public string $currencyId;

	public static function __set_state(array $array): static
	{
		$dto = new static();
		if (isset($array['sum']))
		{
			$dto->sum = $array['sum'];
		}
		if (isset($array['currencyId']))
		{
			$dto->currencyId = $array['currencyId'];
		}

		return $dto;
	}
}
