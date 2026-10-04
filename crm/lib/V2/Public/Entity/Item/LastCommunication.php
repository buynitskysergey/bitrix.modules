<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Main\Type\DateTime;

final readonly class LastCommunication
{
	public function __construct(
		private ?DateTime $communicationTime = null,
		private ?DateTime $callTime = null,
		private ?DateTime $emailTime = null,
		private ?DateTime $imolTime = null,
		private ?DateTime $webformTime = null,
	)
	{
	}

	public function getCommunicationTime(): ?DateTime
	{
		return $this->communicationTime;
	}

	public function getCallTime(): ?DateTime
	{
		return $this->callTime;
	}

	public function getEmailTime(): ?DateTime
	{
		return $this->emailTime;
	}

	public function getImolTime(): ?DateTime
	{
		return $this->imolTime;
	}

	public function getWebformTime(): ?DateTime
	{
		return $this->webformTime;
	}
}
