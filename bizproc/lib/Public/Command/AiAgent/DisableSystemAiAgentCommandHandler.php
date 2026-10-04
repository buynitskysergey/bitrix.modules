<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\AiAgent;

use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\SystemAiAgentLifecycleService;
use Bitrix\Bizproc\Public\Command\AiAgent\Enum\SystemAiAgentErrorCode;
use Bitrix\Bizproc\Public\Command\AiAgent\Result\SystemAiAgentLifecycleResult;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Delegates the removal to the single lifecycle service of the module.
 *
 * The service validates the input itself and already answers with the public codes of the result, so nothing is
 * checked twice here. An unexpected failure - a service that is not in the locator, an infrastructure error - is
 * turned into a failed result with a stable code instead of leaving the boundary of the command as an exception.
 */
final class DisableSystemAiAgentCommandHandler
{
	public function __invoke(DisableSystemAiAgentCommand $command): SystemAiAgentLifecycleResult
	{
		try
		{
			return ServiceLocator::getInstance()
				->get(SystemAiAgentLifecycleService::SERVICE_CODE)
				->disable($command->systemCode, $command->context)
			;
		}
		catch (\Throwable)
		{
			return SystemAiAgentLifecycleResult::createFailure(SystemAiAgentErrorCode::OperationFailed);
		}
	}
}
