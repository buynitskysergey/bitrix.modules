<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc;

use Bitrix\Bizproc\Internal\AiAgent\Service\AgentLauncher;
use Bitrix\Bizproc\Internal\AiAgent\Service\LaunchAgentRequest;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class AiAgentLauncher
{
	/**
	 * @param array<string, mixed> $constants
	 */
	public function launch(int $systemTemplateId, int $userId, array $constants = []): Result
	{
		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error('Bizproc module is not available'));
		}

		$startResult = AgentLauncher::create()->launch(new LaunchAgentRequest(
			systemTemplateId: $systemTemplateId,
			userId: $userId,
			constants: $constants,
		));

		return (new Result())->addErrors($startResult->getErrors());
	}
}
