<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\AiAgent;

use Bitrix\Bizproc\Public\Command\AiAgent\Dto\SystemAiAgentContext;
use Bitrix\Bizproc\Public\Command\AiAgent\Enum\SystemAiAgentErrorCode;
use Bitrix\Bizproc\Public\Command\AiAgent\Result\SystemAiAgentLifecycleResult;
use Bitrix\Main\Command\AbstractCommand;

/**
 * Deletes the managed instance of the system AI agent of the given system code completely.
 *
 * Disabling means a full removal and not a pause: the managed copy of the template, its schedules, its running
 * workflows, the bots it owns and its storage scope all go away together with the service binding. The history of
 * a finished workflow, a template created by hand, a bot that existed before the enabling and a shared storage
 * type are never touched.
 *
 * The instance is identified by the pair of the system code and the context only: that pair is its stable public
 * representation, and an arbitrary template id is not accepted. A removal needs no delivered system template to
 * still be installed, and a context without a binding answers the outcome already_disabled.
 *
 * One call performs one bounded pass. An instance whose resources do not fit that pass comes back as a failed
 * result with the outcome cleanup_pending: the removal is saved and continued by the background pass of the
 * module, so the caller repeats the command only if it wants to know the state earlier.
 *
 * Call it outside a transaction of the caller. The outcome cleanup_pending is a promise that the removal is stored
 * and will be continued, and that promise is only as durable as the write behind it: inside a transaction of the
 * caller the stored continuation is a savepoint, so a rollback would answer that the removal goes on while nothing
 * of it was kept, leaving the deleted resources without the binding that would finish the job.
 *
 * Trusted server side cross module call only. The command is not published through REST or a controller directly,
 * because it checks no application right of its own: an adapter that exposes it has to check the calling user and
 * the application object it acts upon separately. The removal itself runs in the internal system context and does
 * not read the global current user.
 *
 * An expected rejection is a value of the result and not an exception, so a malformed context comes back from
 * {@see self::run()} as a failed {@see SystemAiAgentLifecycleResult} with a stable code of
 * {@see SystemAiAgentErrorCode}.
 */
final class DisableSystemAiAgentCommand extends AbstractCommand
{
	/**
	 * @param string $systemCode code of the delivered system AI agent, for example bitrix_ai_project_pulse
	 * @param SystemAiAgentContext $context external object the instance belongs to
	 */
	public function __construct(
		public readonly string $systemCode,
		public readonly SystemAiAgentContext $context,
	)
	{
	}

	protected function execute(): SystemAiAgentLifecycleResult
	{
		return (new DisableSystemAiAgentCommandHandler())($this);
	}
}
