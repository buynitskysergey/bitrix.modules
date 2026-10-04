<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto;

use Bitrix\Main\Infrastructure\Rest\Dto\Mapping\ConsentMapper;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(ConsentMapper::class)]
class ConsentDto extends Dto
{
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	public int $agreementId;

	#[Editable(['add'])]
	public ?int $userId;

	#[Required(['add'])]
	#[Editable(['add'])]
	public string $ip;

	#[Editable(['add'])]
	public ?string $url;

	#[Editable(['add'])]
	public ?string $originId;

	#[Editable(['add'])]
	public ?string $originatorId;
}
