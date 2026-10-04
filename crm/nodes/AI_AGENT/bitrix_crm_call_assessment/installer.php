<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Template\NodesInstaller;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\BizProc\CallAssessmentAiAgent;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;
use Psr\Log\LogLevel;

return new class extends NodesInstaller
{
	private ?string $copilotName = null;

	public function getModifiedTime(): int
	{
		return /*mtime*/1786613711/*mtime*/;
	}

	public function onInstall(int $templateId): void
	{
		$this->restoreLaunchedCopy(__FUNCTION__);
	}

	public function onUpdate(int $templateId): void
	{
		$this->restoreLaunchedCopy(__FUNCTION__);
	}

	/**
	 * Both hooks are an auxiliary path of the restore, not its owner: the install hook fires once per portal, and
	 * the update hook is skipped for a blueprint marked as modified by hand. The owner of the invariant is
	 * Bitrix\Crm\Agent\Copilot\CallScoringV2BootstrapAgent.
	 *
	 * The template id of the hook is deliberately not passed on. ensureLaunched() resolves the blueprint itself and
	 * is the only place allowed to create a copy: the launch it calls is not idempotent, so calling it from here
	 * would produce a second active copy. Both a refusal and an exception of the restore are logged and go no
	 * further: the synchronization swallows throwables of a lifecycle hook, which leaves the journal as the only way
	 * to see them, and the installation of the node must not fail because of an auxiliary path.
	 *
	 * A restore another process is holding the lock of is not a refusal: that process finishes it, and this hook is
	 * one of the paths that collide over the lock. It is recorded as the normal state it is, the way the owner of the
	 * invariant records it - the journal of the feature is read as a call for a manual investigation.
	 */
	private function restoreLaunchedCopy(string $hook): void
	{
		try
		{
			$result = (new CallAssessmentAiAgent())->ensureLaunched(CallAssessmentAiAgent::RESTORE_USER_ID);
		}
		catch (\Throwable $exception)
		{
			$this->logOutcome($hook, LogLevel::ERROR, 'failed with an exception: {error}', [
				'error' => $exception->getMessage(),
			]);

			return;
		}

		if ($result->isSuccess())
		{
			return;
		}

		$context = ['errors' => implode('; ', $result->getErrorMessages())];
		$heldByAnotherProcess = $result
			->getErrorCollection()
			->getErrorByCode(ErrorCode::CALL_ASSESSMENT_AGENT_RESTORE_IN_PROGRESS) !== null
		;

		if ($heldByAnotherProcess)
		{
			$this->logOutcome($hook, LogLevel::INFO, 'is already in progress: {errors}', $context);

			return;
		}

		$this->logOutcome($hook, LogLevel::ERROR, 'failed: {errors}', $context);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function logOutcome(string $hook, string $level, string $reason, array $context): void
	{
		Container::getInstance()->getLogger(CallAssessmentAiAgent::LOGGER_CHANNEL)->log(
			$level,
			'{date}: {class}: restore of the agent copy from the {hook} hook ' . $reason,
			[
				'class' => 'crm/nodes/AI_AGENT/' . CallAssessmentAiAgent::SYSTEM_CODE,
				'hook' => $hook,
			] + $context,
		);
	}

	public function getMessageReplacements(): array
	{
		$copilotReplacement = ['#COPILOT_NAME#' => $this->getCopilotName()];

		return [
			'BIZPROC_NODES_BITRIX_CRM_CALL_ASSESSMENT_DESCRIPTION_2' => $copilotReplacement,
			'BIZPROC_NODES_BITRIX_CRM_CALL_ASSESSMENT_DESCRIPTION_3' => $copilotReplacement,
		];
	}

	private function getCopilotName(): string
	{
		if ($this->copilotName === null)
		{
			$this->copilotName = Loader::includeModule('ui') && class_exists(CopilotNameService::class)
				? (new CopilotNameService())->getCopilotName()
				: 'BitrixGPT'
			;
		}

		return $this->copilotName;
	}
};
