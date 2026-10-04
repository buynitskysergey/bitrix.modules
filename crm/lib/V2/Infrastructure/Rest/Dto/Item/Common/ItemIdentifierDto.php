<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

class ItemIdentifierDto extends Dto
{
	#[Editable]
	#[Required(['add', 'update'])]
	#[PositiveNumber]
	public int $entityTypeId;

	#[Editable]
	#[Required(['add', 'update'])]
	#[PositiveNumber]
	public int $entityId;

	public static function __set_state(array $array): static
	{
		$dto = new static();
		if (isset($array['entityTypeId']))
		{
			$dto->entityTypeId = $array['entityTypeId'];
		}
		if (isset($array['entityId']))
		{
			$dto->entityId = $array['entityId'];
		}

		return $dto;
	}
}
