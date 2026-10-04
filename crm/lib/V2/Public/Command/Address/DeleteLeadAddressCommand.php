<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Address;

use Bitrix\Crm\V2\Internal\Service\Address\DeleteLeadAddressCommandHandler;
use Bitrix\Main\Command\CommandInterface;
use Bitrix\Main\Result;

final class DeleteLeadAddressCommand implements CommandInterface
{
	public function __construct(
		private readonly int $leadId,
		private readonly int $userId,
	)
	{
	}

	public function getLeadId(): int
	{
		return $this->leadId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function run(): Result
	{
		return (new DeleteLeadAddressCommandHandler())->handle($this);
	}
}
