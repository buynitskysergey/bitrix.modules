<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\AiAgent;

use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\SystemAiAgentLifecycleService;
use Bitrix\Bizproc\Public\Command\AiAgent\Enum\SystemAiAgentErrorCode;
use Bitrix\Bizproc\Public\Command\AiAgent\Result\SystemAiAgentLifecycleResult;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Delegates the enabling to the single lifecycle service of the module.
 *
 * The service validates the input itself and already answers with the public codes of the result, so nothing is
 * checked twice here. An unexpected failure - a service that is not in the locator, an infrastructure error - is
 * turned into a failed result with a stable code instead of leaving the boundary of the command as an exception.
 */
final class EnableSystemAiAgentCommandHandler
{
	public function __invoke(EnableSystemAiAgentCommand $command): SystemAiAgentLifecycleResult
	{
		try
		{
			return ServiceLocator::getInstance()
				->get(SystemAiAgentLifecycleService::SERVICE_CODE)
				->enable($command->systemCode, $command->context, $command->userId, $command->activation)
			;
		}
		catch (\Throwable)
		{
			return SystemAiAgentLifecycleResult::createFailure(SystemAiAgentErrorCode::OperationFailed);
		}
	}
}
