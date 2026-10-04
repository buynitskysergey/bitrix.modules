<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Interface;

use Bitrix\Main\Type\Date;

interface HasBeginCloseDatesInterface
{
	public function getBeginDate(): ?Date;

	public function setBeginDate(?Date $beginDate): static;

	public function getCloseDate(): ?Date;

	public function setCloseDate(?Date $closeDate): static;
}
