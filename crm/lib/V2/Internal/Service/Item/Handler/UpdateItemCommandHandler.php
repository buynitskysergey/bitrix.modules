<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Handler;

use Bitrix\Crm\V2\Internal\Service\Item\Operation\ItemOperationRunner;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Main\Result;

/**
 * @internal
 */
class UpdateItemCommandHandler
{
	private readonly ItemOperationRunner $operationRunner;

	public function __construct(?ItemOperationRunner $operationRunner = null)
	{
		$this->operationRunner = $operationRunner ?? new ItemOperationRunner();
	}

	public function handle(UpdateItemCommand $command): Result
	{
		return $this->operationRunner->run($command);
	}
}
