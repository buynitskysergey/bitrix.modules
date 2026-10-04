<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\Dto;

class CurrencyDto extends Dto
{
	public string $id;
	public ?string $fullName;
	public ?string $formatString;
	public ?string $decPoint;
	public ?string $thousandsSep;
	public ?int $decimals;
	public ?bool $hideZero;
	public ?float $amount;
	public ?int $amountCount;
	public bool $base;
	public ?int $sort;
	public ?string $numericCode;
	public ?string $languageId;
	public ?DateTime $createdTime;
	public ?DateTime $updatedTime;
	public ?int $createdById;
	public ?int $updatedById;
}
