<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Cleanup;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResource;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;

/**
 * Repeatable cleanup of the resources of one type owned by a managed system AI agent instance.
 *
 * Every supported resource type has a registration, a way to prove ownership and a participant; a durable
 * resource without a participant is not supported by the public API at all. A participant is called inside
 * one bounded pass and has to be safe to call again: an interrupted deletion, a resource that is already
 * gone and a resource that was never created all end as a complete result instead of an error.
 *
 * Both methods share the one mutable {@see ManagedResourceCleanupBudget} of the pass and account in it every
 * actual or service row they read or delete, so a large instance is spread over several passes instead of
 * exhausting the request. The outcome of a call is chosen in this order:
 * <ul>
 * <li> the budget of the pass is exhausted before any work could be done - pending;
 * <li> the completion criterion of the type is confirmed - complete;
 * <li> a bounded portion of the work is done while actual work remains - pending;
 * <li> an external deletion is accepted and the resource is still there - blocked;
 * <li> no progress was made and {@see ManagedResourceCleanupBudget::isParticipantOverdue()} - failed with a
 *      stable internal code, so that a stuck external dependency does not report an endless pending.
 * </ul>
 *
 * A blocked and a failed outcome are charged with a retry delay and a pending one is not, therefore an expected
 * condition that is going to be resolved by the next pass belongs to pending, while an outcome that repeats
 * without any progress belongs to one of the other two. An exception is not a way to report any of them: the
 * lifecycle orchestrator converts an escaping exception into a failed result with a stable code of its own,
 * keeps the instance in cleanup and never lets the exception cross the boundary of the public command.
 *
 * Cleanup runs in the internal system context: the stored user of the instance may already be absent or
 * blocked, therefore $USER, CurrentUser and any other global user state are not consulted.
 */
interface ManagedResourceCleanupInterface
{
	/**
	 * Resource type this participant cleans up.
	 *
	 * The type is answered by the instance and not by the class, so one implementation may serve several
	 * types as several separately constructed and separately registered participants.
	 */
	public function type(): ManagedAgentResourceType;

	/**
	 * Reconciles the registered resources of this type with the actual tables of the instance before any of
	 * them is cleaned up, and again under the closed creation barrier before the service link is removed.
	 *
	 * The reconciliation closes the window between the creation of a resource and its registration: a live
	 * resource of the instance found without a registration is registered here, and a registration whose
	 * ownership no longer holds is dropped. A complete result therefore states that the registry of this type
	 * describes the actual state, not that the type is already empty: the registered rows are cleaned up by
	 * {@see self::cleanup()} afterwards.
	 *
	 * A reconciliation walks what is still live, so it may not spend the budget the deletion of the same pass
	 * needs: it runs inside the reconciliation phase of the budget and reads its live resources in bounded
	 * portions. A portion it did not reach is a pending result and is continued by the next pass, which meets
	 * fewer resources because this one removed some of them. The caller does not stop at a pending
	 * reconciliation either - it cleans up the rows that are already registered and keeps the outcome of the
	 * type pending, hence a removal still never reports a final success while a resource is left.
	 */
	public function reconcile(
		ManagedAgentInstance $instance,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult;

	/**
	 * Removes one registered resource of this type, or confirms that it is already absent.
	 *
	 * The caller deletes the service row only after a complete result, so the row has to stay while the
	 * result is pending: it is the only proof of ownership the next pass is left with. An external module
	 * that is unavailable leaves the operation unfinished and is never read as a successful absence.
	 *
	 * The technical data of the resource are read through the closed schema of its type. A version the
	 * participant does not know gives a failed result and never an attempt to delete by unverified data.
	 *
	 * @param ManagedAgentResource $resource registered resource of the {@see self::type()} type
	 */
	public function cleanup(
		ManagedAgentResource $resource,
		ManagedResourceCleanupBudget $budget,
	): ManagedResourceCleanupResult;
}
