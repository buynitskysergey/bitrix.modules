<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\V2\Public\Command\Address\DeleteLeadAddressCommand;
use Bitrix\Main\Result;

/** @internal */
final class DeleteLeadAddressCommandHandler
{
	private readonly LeadAddressService $leadAddressService;

	public function __construct(?LeadAddressService $leadAddressService = null)
	{
		$this->leadAddressService = $leadAddressService ?? new LeadAddressService();
	}

	public function handle(DeleteLeadAddressCommand $command): Result
	{
		$this->leadAddressService->assertCanUpdate($command->getLeadId(), $command->getUserId());
		$this->leadAddressService->deletePrimary($command->getLeadId());

		return new Result();
	}
}
