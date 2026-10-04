<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

class MultifieldsDto extends Dto
{
	public const MAX_VALUES_PER_FIELD = 50;

	public const MAX_VALUES_TOTAL = 100;

	#[Editable]
	#[ElementType(MultifieldDto::class)]
	public ?DtoCollection $phone;

	#[Editable]
	#[ElementType(MultifieldDto::class)]
	public ?DtoCollection $email;

	#[Editable]
	#[ElementType(MultifieldDto::class)]
	public ?DtoCollection $web;

	#[Editable]
	#[ElementType(MultifieldDto::class)]
	public ?DtoCollection $im;

	public bool $hasPhone;

	public bool $hasEmail;

	public bool $hasWeb;

	public bool $hasIm;
}
