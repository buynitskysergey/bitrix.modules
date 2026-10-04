<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\AiAgent;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResource;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Main\Repository\Exception\PersistenceException;

/**
 * Durable registry of live resources owned by managed system AI agent instances.
 *
 * A row states ownership only: retry bookkeeping and the cleanup deadline belong to the instance. Rows are
 * removed explicitly, one resource at a time, because no foreign key links them to the instance.
 */
interface ManagedAgentResourceRepositoryInterface
{
	/**
	 * Reads the row by its logical key over ux_bp_ma_resource_identity.
	 *
	 * Registering an already registered resource is a safe operation: the caller looks the row up first and
	 * decides on its own whether the stored payload may be replaced.
	 */
	public function findByLogicalKey(
		int $instanceId,
		ManagedAgentResourceType $type,
		string $resourceId,
	): ?ManagedAgentResource;

	/**
	 * Resource ids of one portion that are already registered for this instance and type, over the same
	 * ux_bp_ma_resource_identity key as {@see self::findByLogicalKey()}.
	 *
	 * The reconciliation of a type asks about the whole portion of live resources it has just read, so a portion
	 * costs one query instead of one per resource. The answer carries the ids alone: the caller only decides
	 * which of them are missing, and a resource it has to write is written from what it read itself.
	 *
	 * The caller passes one bounded portion, which is what the batch limits of the participants are for.
	 *
	 * @param list<string> $resourceIds
	 * @return list<string> subset of $resourceIds, in no particular order
	 */
	public function findRegisteredResourceIds(
		int $instanceId,
		ManagedAgentResourceType $type,
		array $resourceIds,
	): array;

	/**
	 * Id of the ownership row of the resource, or null when nobody owns it.
	 *
	 * The lookup goes over ix_bp_ma_resource_lookup and answers the caller that only has to drop the row: the
	 * release runs on the completion path of every workflow of the portal, where neither the whole entity nor
	 * its mapper is worth building. A producer must keep the resource id unique for the copy of the template, so
	 * at most one owner exists; that key is not unique in the schema, though - only (INSTANCE_ID, TYPE,
	 * RESOURCE_ID) is - therefore two instances are able to claim one resource. Such a pair is answered by the
	 * lowest id, so a repeated release walks the rows one by one instead of picking among them at random.
	 *
	 * The null of this method is allowed to be a false one, and that is a part of the contract, not of a single
	 * implementation. A portal that has never owned a managed resource may be answered without asking the storage
	 * at all, which is what keeps the completion of an ordinary workflow free of a lookup. A resource registered
	 * by the request itself is never read as absent. What may still be answered as absent is a resource of the
	 * first agent a parallel request is enabling right now, and only for a request that started before it.
	 *
	 * The cost of such an answer is bounded on purpose: a release that found nothing leaves the ownership row of
	 * an already finished resource behind, which claims a resource that is over and never the other way round.
	 * That row is picked up by the reconciliation of the instance and by the cleanup participant of its type, so
	 * a caller of this method never has to compensate a false null on its own.
	 */
	public function findIdByTypeAndResourceId(ManagedAgentResourceType $type, string $resourceId): ?int;

	/**
	 * Reads the next portion of registered resources of one type by the ix_bp_ma_resource_cleanup key.
	 *
	 * @param int $afterId exclusive lower bound of the previous portion, zero for the first one
	 * @return ManagedAgentResource[] ordered by id ascending
	 */
	public function findBatchByType(
		int $instanceId,
		ManagedAgentResourceType $type,
		int $limit,
		int $afterId = 0,
	): array;

	/**
	 * Tells whether resources of this type are still registered, so that the pass knows it is not complete yet.
	 */
	public function hasResourcesOfType(int $instanceId, ManagedAgentResourceType $type): bool;

	/**
	 * Tells whether the registry of the instance is empty, which a removal pass requires before it deletes the link.
	 */
	public function hasResources(int $instanceId): bool;

	/**
	 * Inserts a new row or updates a stored one and returns the resource with the persisted id.
	 *
	 * @throws PersistenceException
	 */
	public function save(ManagedAgentResource $resource): ManagedAgentResource;

	/**
	 * Removes the ownership row of a resource that is confirmed absent or compensated.
	 *
	 * @throws PersistenceException
	 */
	public function delete(int $id): void;
}
