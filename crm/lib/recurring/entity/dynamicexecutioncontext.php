<?php

namespace Bitrix\Crm\Recurring\Entity;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

final readonly class DynamicExecutionContext
{
	private DateTime $serverDateTime;
	private Date $executionDate;
	private int $operationUserId;

	public function __construct(DateTime $serverDateTime, Date $executionDate, int $operationUserId)
	{
		$this->serverDateTime = clone $serverDateTime;
		$this->executionDate = clone $executionDate;
		$this->operationUserId = $operationUserId;
	}

	public function getServerDateTime(): DateTime
	{
		return clone $this->serverDateTime;
	}

	public function getExecutionDate(): Date
	{
		return clone $this->executionDate;
	}

	public function getOperationUserId(): int
	{
		return $this->operationUserId;
	}
}
