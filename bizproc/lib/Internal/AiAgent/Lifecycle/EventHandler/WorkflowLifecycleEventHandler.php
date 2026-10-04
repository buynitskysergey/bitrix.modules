<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\EventHandler;

use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\ManagedAgentResourceRegistry;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

/**
 * Connects the lifecycle events of a workflow to {@see ManagedAgentResourceRegistry}: the barrier decides
 * whether a workflow of a managed system AI agent copy may start at all, and the events that end a workflow
 * drop the ownership row of a resource that is no longer live.
 *
 * The handler carries no logic of its own. Ownership, classification, the logical lock and the one time marker
 * of an enabling operation all live in the registry, which is resolved as the one shared instance of the
 * request: a handler with a registry of its own would not see the operation scope of the orchestrator.
 *
 * The two directions are deliberately asymmetric. A refused barrier aborts the creation of the workflow,
 * because a workflow whose id nobody owns would outlive the removal of its instance. A failed release, in
 * contrast, never breaks the completion of a workflow: letting a process finish matters more than the
 * bookkeeping, which the reconciliation of the cleanup and the background pass repeat anyway.
 */
final class WorkflowLifecycleEventHandler
{
	/**
	 * Barrier of a starting workflow, called before CBPWorkflow::initialize() and CBPStateService::addWorkflow(),
	 * so the future workflow id is owned before any state of it exists.
	 *
	 * Compatible bizproc event OnCreateWorkflow, whose positional arguments are the template id, the document id,
	 * the start parameters by reference and the id the workflow is about to get. The parameters are taken by
	 * reference because the registry consumes the internal one time marker of an enabling operation out of them.
	 *
	 * @throws SystemException when the barrier refuses the workflow, which aborts its creation
	 */
	public static function onCreateWorkflow(
		mixed $templateId,
		mixed $documentId,
		array &$parameters,
		mixed $workflowId,
	): void
	{
		$registry = self::getRegistry();
		if ($registry === null)
		{
			return;
		}

		$result = $registry->registerWorkflowCreation((int)$templateId, (string)$workflowId, $parameters);
		if (!$result->isSuccess())
		{
			throw new SystemException(
				'A managed system AI agent resource barrier refused the workflow: ' . self::describeRefusal($result),
			);
		}
	}

	/**
	 * Compatible bizproc event OnWorkflowComplete, fired for a completed and for a terminated workflow alike.
	 * Its second positional argument, the status, is not read: both statuses mean the workflow is over.
	 */
	public static function onWorkflowComplete(mixed $workflowId): void
	{
		self::releaseWorkflow((string)$workflowId);
	}

	/**
	 * Modern bizproc event onAfterWorkflowKill.
	 */
	public static function onAfterWorkflowKill(Event $event): void
	{
		self::releaseWorkflow((string)$event->getParameter('ID'));
	}

	/**
	 * Drops the ownership row of a workflow that has ended.
	 *
	 * One indexed read over (TYPE, RESOURCE_ID) inside the registry answers whether the workflow was managed at
	 * all, so an unmanaged one finishes immediately. The result is knowingly not raised: an accounting failure
	 * must not stop a workflow from ending, and a row left behind is picked up by the reconciliation.
	 */
	private static function releaseWorkflow(string $workflowId): void
	{
		if ($workflowId === '')
		{
			return;
		}

		self::getRegistry()?->releaseByResourceId(ManagedAgentResourceType::Workflow, $workflowId);
	}

	/**
	 * Stable code and reason of the refusal, both of them constants of the registry, hence safe to report.
	 */
	private static function describeRefusal(Result $result): string
	{
		$error = $result->getErrors()[0] ?? null;
		if (!($error instanceof Error))
		{
			return 'unknown';
		}

		$customData = $error->getCustomData();
		$reason = is_array($customData)
			? ($customData[ManagedAgentResourceRegistry::CUSTOM_DATA_REASON] ?? null)
			: null
		;

		return is_string($reason) ? $error->getCode() . '/' . $reason : (string)$error->getCode();
	}

	/**
	 * The shared registry of the request, or null while the service is not in the locator.
	 *
	 * An absent registration is the same exception the registry itself makes for tables that are not installed
	 * yet: without it no managed instance can be created, so nothing is able to leave a workflow without an
	 * owner, whereas refusing every workflow of the portal would be a far worse answer.
	 */
	private static function getRegistry(): ?ManagedAgentResourceRegistry
	{
		$locator = ServiceLocator::getInstance();

		return $locator->has(ManagedAgentResourceRegistry::SERVICE_CODE)
			? $locator->get(ManagedAgentResourceRegistry::SERVICE_CODE)
			: null
		;
	}
}
