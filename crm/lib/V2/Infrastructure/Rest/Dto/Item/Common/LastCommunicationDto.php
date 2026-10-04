<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;

class LastCommunicationDto extends Dto
{
	#[Filterable]
	public ?DateTime $communicationTime;
	#[Filterable]
	public ?DateTime $callTime;
	#[Filterable]
	public ?DateTime $emailTime;
	#[Filterable]
	public ?DateTime $imolTime;
	#[Filterable]
	public ?DateTime $webformTime;
}
