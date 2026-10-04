<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\SystemUser;

use Bitrix\Main;

class DeactivateIntegrationsCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Main\Result
	{
		return (new DeactivateIntegrationsCommandHandler())($this);
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
		];
	}
}
