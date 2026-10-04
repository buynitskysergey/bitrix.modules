<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Max;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Min;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

class AddressDto extends Dto
{
	#[Editable]
	#[Required(['add', 'update'])]
	#[NotEmpty]
	public string $address;

	#[Editable]
	#[Min(-90)]
	#[Max(90)]
	public ?float $latitude;

	#[Editable]
	#[Min(-180)]
	#[Max(180)]
	public ?float $longitude;

	public static function __set_state(array $array): static
	{
		$dto = new static();
		if (isset($array['address']))
		{
			$dto->address = $array['address'];
		}
		if (isset($array['latitude']))
		{
			$dto->latitude = $array['latitude'];
		}
		if (isset($array['longitude']))
		{
			$dto->longitude = $array['longitude'];
		}

		return $dto;
	}
}
