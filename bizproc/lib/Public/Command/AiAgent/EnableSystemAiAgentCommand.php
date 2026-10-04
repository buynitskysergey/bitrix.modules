<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\AiAgent;

use Bitrix\Bizproc\Public\Command\AiAgent\Dto\SystemAiAgentActivationParameters;
use Bitrix\Bizproc\Public\Command\AiAgent\Dto\SystemAiAgentContext;
use Bitrix\Bizproc\Public\Command\AiAgent\Enum\SystemAiAgentErrorCode;
use Bitrix\Bizproc\Public\Command\AiAgent\Result\SystemAiAgentLifecycleResult;
use Bitrix\Main\Command\AbstractCommand;

/**
 * Enables the system AI agent of the given system code for one external object.
 *
 * The instance is identified by the pair of the system code and the context only: that pair is its stable public
 * representation, and an arbitrary template id is not accepted. Repeating the very same call creates no second
 * copy and answers the outcome already_enabled, while the same context with another effective configuration
 * answers {@see SystemAiAgentErrorCode::ConfigurationConflict}.
 *
 * The agent is launched from the explicitly passed user: the command never reads the global current user, and the
 * result never carries an internal identifier of the copy or of the instance.
 *
 * Call it outside a transaction of the caller. The enabling starts a workflow, creates the bots of the copy and
 * writes its schedules, and it commits those writes itself as it goes; inside a transaction of the caller they
 * would become savepoints instead, so a rollback of that caller would leave a started process and created bots
 * behind while taking the service binding that owns them away.
 *
 * Trusted server side cross module call only. The command is not published through REST or a controller directly,
 * because it checks no application right of its own: an adapter that exposes it has to check the calling user and
 * the application object it acts upon separately.
 *
 * An expected rejection is a value of the result and not an exception, so a malformed context, an unavailable
 * agent or an invalid value comes back from {@see self::run()} as a failed {@see SystemAiAgentLifecycleResult}
 * with a stable code of {@see SystemAiAgentErrorCode}.
 */
final class EnableSystemAiAgentCommand extends AbstractCommand
{
	/**
	 * @param string $systemCode code of the delivered system AI agent, for example bitrix_ai_project_pulse
	 * @param SystemAiAgentContext $context external object the instance belongs to
	 * @param int $userId user the agent is launched from
	 * @param SystemAiAgentActivationParameters $activation values of the declared activation sections; a section
	 *  added later is one more field of the DTO and does not change this signature
	 */
	public function __construct(
		public readonly string $systemCode,
		public readonly SystemAiAgentContext $context,
		public readonly int $userId,
		public readonly SystemAiAgentActivationParameters $activation = new SystemAiAgentActivationParameters(),
	)
	{
	}

	protected function execute(): SystemAiAgentLifecycleResult
	{
		return (new EnableSystemAiAgentCommandHandler())($this);
	}
}
