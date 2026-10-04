<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\AiAgent;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstanceState;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;

/**
 * Durable registry of managed system AI agent instances.
 *
 * Every lookup returns the whole instance, including the context components, so that the caller can confirm
 * an identity hash match against the stored namespace, type and id instead of trusting the hash alone.
 * UPDATED_AT is written by the application on every change; there is no database trigger behind it.
 */
interface ManagedAgentInstanceRepositoryInterface
{
	/**
	 * Rereads the instance by its surrogate id, for example to confirm a persisted state and retry deadline.
	 */
	public function getById(int $id): ?ManagedAgentInstance;

	/**
	 * Reads the instance by the uniqueness source of truth, using ux_bp_ma_instance_identity.
	 */
	public function findByIdentityHash(string $identityHash): ?ManagedAgentInstance;

	/**
	 * Tells a managed copy from an unmanaged template by a single read over ux_bp_ma_instance_template.
	 */
	public function findByTemplateId(int $templateId): ?ManagedAgentInstance;

	/**
	 * Selects instances in the given states whose NEXT_RETRY_AT is empty or already due at $dueAt.
	 *
	 * The predicate and the order follow ix_bp_ma_instance_cleanup, so the scan stays bounded by $limit.
	 *
	 * @param ManagedAgentInstanceState[] $states
	 * @return ManagedAgentInstance[] ordered by state, next retry date and id
	 */
	public function findDueByStates(array $states, DateTime $dueAt, int $limit): array;

	/**
	 * Reads the row and holds it locked until the current transaction ends.
	 *
	 * Must be called inside a transaction; the caller stays responsible for the logical lock of the identity.
	 *
	 * @throws PersistenceException
	 */
	public function lockById(int $id): ?ManagedAgentInstance;

	/**
	 * Inserts a new instance or updates a stored one and returns the instance with the persisted id.
	 *
	 * A unique key violation is reported as an exception and never retried as a blind insert: the caller
	 * rereads the row by identity hash and compares the stored components itself.
	 *
	 * @throws PersistenceException
	 */
	public function save(ManagedAgentInstance $instance): ManagedAgentInstance;

	/**
	 * Moves the instance to $state only while its stored state is one of $expectedStates.
	 *
	 * @param ManagedAgentInstanceState[] $expectedStates
	 * @return bool false when the stored state did not match and nothing was written
	 * @throws PersistenceException
	 */
	public function compareAndSetState(
		int $id,
		array $expectedStates,
		ManagedAgentInstanceState $state,
		DateTime $updatedAt,
	): bool;

	/**
	 * Confirms the enabling to enabled transition and clears the watchdog deadline, retry counter and last error.
	 *
	 * @return bool false when the instance was no longer enabling and nothing was written
	 * @throws PersistenceException
	 */
	public function markEnabled(int $id, DateTime $updatedAt): bool;

	/**
	 * Removes the instance link, which is always the last row deleted by a full removal pass.
	 *
	 * @throws PersistenceException
	 */
	public function delete(int $id): void;
}
