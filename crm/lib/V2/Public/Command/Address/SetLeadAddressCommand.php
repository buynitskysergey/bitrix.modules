<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Address;

use Bitrix\Crm\V2\Internal\Service\Address\SetLeadAddressCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Address\LeadAddress;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Result;

final class SetLeadAddressCommand implements CommandInterface
{
	public function __construct(
		private readonly int $leadId,
		private readonly LeadAddress $changes,
		private readonly int $userId,
	)
	{
	}

	public function getLeadId(): int
	{
		return $this->leadId;
	}

	public function getChanges(): LeadAddress
	{
		return $this->changes;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function run(): Result
	{
		return (new SetLeadAddressCommandHandler())->handle($this);
	}
}
