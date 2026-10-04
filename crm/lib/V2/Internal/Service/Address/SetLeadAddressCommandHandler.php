<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\V2\Public\Command\Address\SetLeadAddressCommand;
use Bitrix\Main\Result;

/** @internal */
final class SetLeadAddressCommandHandler
{
	private readonly LeadAddressService $leadAddressService;

	public function __construct(?LeadAddressService $leadAddressService = null)
	{
		$this->leadAddressService = $leadAddressService ?? new LeadAddressService();
	}

	public function handle(SetLeadAddressCommand $command): Result
	{
		$this->leadAddressService->assertCanUpdate($command->getLeadId(), $command->getUserId());
		if ($command->getChanges()->getChangedFields() === [])
		{
			return new Result();
		}

		$this->leadAddressService->updatePrimary($command->getLeadId(), $command->getChanges());

		return new Result();
	}
}
