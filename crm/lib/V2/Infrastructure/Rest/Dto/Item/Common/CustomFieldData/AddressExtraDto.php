<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\CustomFieldData;

use Bitrix\Rest\V3\Dto\Dto;

class AddressExtraDto extends Dto
{
	public ?int $locationId = null;
	public ?float $latitude = null;
	public ?float $longitude = null;
	public ?string $country = null;
	public ?string $city = null;
	public ?string $postalCode = null;
	public ?string $address1 = null;
	public ?string $address2 = null;
}
