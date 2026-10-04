<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto;

use Bitrix\Main\Infrastructure\Rest\Dto\Mapping\LikeItemMapper;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(LikeItemMapper::class)]
class LikeItemDto extends Dto
{
	#[NotEmpty]
	#[Filterable]
	public string $entityTypeId;

	#[Min(1)]
	#[Filterable]
	public int $entityId;

	#[Filterable]
	#[NotEmpty]
	public ?string $pathToUserProfile;

	#[Filterable]
	#[NotEmpty]
	public ?string $reaction;

	public ?int $userId;

	public ?int $voteValue;

	public ?string $photo;

	public ?string $photoSrc;

	public ?string $fullName;

	public ?string $url;

	public ?string $userType;
}
