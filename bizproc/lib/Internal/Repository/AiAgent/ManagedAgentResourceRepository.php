<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\AiAgent;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResource;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentResourceType;
use Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentResourceTable;
use Bitrix\Bizproc\Internal\Repository\Mapper\ManagedAgentResourceMapper;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Repository\Exception\PersistenceException;

class ManagedAgentResourceRepository implements ManagedAgentResourceRepositoryInterface
{
	private const MODULE_ID = 'bizproc';

	/**
	 * Option that remembers that this portal has owned a managed resource at least once.
	 *
	 * The release path of every workflow of the portal asks whether the resource is owned, so a portal that never
	 * enabled a system AI agent would pay an indexed lookup on every completion of any process. The fact is
	 * stored among the options of the module, which every request loads in one query anyway, therefore the answer
	 * costs no query of its own and, unlike a memory of the process, it is already there for the next request.
	 *
	 * It is written on the first actual write of this repository and never taken back, which is a decision and
	 * not an omission: a portal whose registry became empty again only pays the ordinary lookup, while a flag
	 * taken back too early would answer that nobody owns a resource and would leave the ownership row of a
	 * completed workflow behind.
	 */
	private const REGISTRY_USED_OPTION = 'ai_agent_registry_used';

	private const OPTION_YES = 'Y';

	/**
	 * @var bool whether this request itself wrote a resource, which the stored flag may not have learned when
	 *  the option could not be written at all
	 */
	private bool $registryUsedByThisRequest = false;

	public function __construct(
		private readonly ManagedAgentResourceMapper $mapper = new ManagedAgentResourceMapper(),
	)
	{
	}

	public function findByLogicalKey(
		int $instanceId,
		ManagedAgentResourceType $type,
		string $resourceId,
	): ?ManagedAgentResource
	{
		if ($instanceId <= 0 || $resourceId === '')
		{
			return null;
		}

		$ormModel = ManagedAgentResourceTable::query()
			->setSelect(['*'])
			->where('INSTANCE_ID', $instanceId)
			->where('TYPE', $type->value)
			->where('RESOURCE_ID', $resourceId)
			->setLimit(1)
			->fetchObject()
		;

		return $ormModel === null ? null : $this->mapper->convertFromOrm($ormModel);
	}

	public function findRegisteredResourceIds(
		int $instanceId,
		ManagedAgentResourceType $type,
		array $resourceIds,
	): array
	{
		$wanted = array_values(array_unique(array_filter(
			$resourceIds,
			static fn (string $resourceId): bool => $resourceId !== '',
		)));

		if ($instanceId <= 0 || $wanted === [])
		{
			return [];
		}

		// One portion of the reconciliation, so the whole set is one indexed lookup and needs no chunking.
		$rows = ManagedAgentResourceTable::query()
			->setSelect(['RESOURCE_ID'])
			->where('INSTANCE_ID', $instanceId)
			->where('TYPE', $type->value)
			->whereIn('RESOURCE_ID', $wanted)
			->setLimit(count($wanted))
			->fetchAll()
		;

		return array_map(static fn (array $row): string => (string)$row['RESOURCE_ID'], $rows);
	}

	/**
	 * Owner of the resource read unconditionally, which is why this method is not part of the interface: every
	 * release of the product goes through the short cut of {@see self::findIdByTypeAndResourceId()} instead.
	 *
	 * It stays as the answer that cannot be a false absence, and that is what a caller of it asks for: proving
	 * that nobody owns a resource is impossible through the other one, whose stored flag lets it answer without
	 * reading the registry at all.
	 */
	public function findByTypeAndResourceId(
		ManagedAgentResourceType $type,
		string $resourceId,
	): ?ManagedAgentResource
	{
		if ($resourceId === '')
		{
			return null;
		}

		$ormModel = ManagedAgentResourceTable::query()
			->setSelect(['*'])
			->where('TYPE', $type->value)
			->where('RESOURCE_ID', $resourceId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetchObject()
		;

		return $ormModel === null ? null : $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * The lookup is skipped altogether while this portal has never owned a managed resource, which is the state
	 * of every portal that never enabled a system AI agent and which the release path of a workflow meets on
	 * every completion. That state is answered by {@see self::REGISTRY_USED_OPTION} and therefore by the options
	 * the request has loaded anyway, so the mass path of a completion reaches no query of the registry at all.
	 *
	 * A resource registered by this request is never read as absent: the write remembers the fact in the object
	 * as well, whether or not the option itself could be stored.
	 *
	 * A parallel request that enables the first agent of the portal is the one case the flag of an already
	 * running request cannot reach, and the consequence is bounded on purpose: a release that found nothing
	 * leaves the ownership row of an already completed workflow behind. That row endangers no invariant - it
	 * claims a workflow that is over, never the other way round - and it is dropped by the reconciliation of the
	 * instance and by the participant that removes the workflows of a copy.
	 */
	public function findIdByTypeAndResourceId(ManagedAgentResourceType $type, string $resourceId): ?int
	{
		if ($resourceId === '' || !$this->isRegistryUsed())
		{
			return null;
		}

		$row = ManagedAgentResourceTable::query()
			->setSelect(['ID'])
			->where('TYPE', $type->value)
			->where('RESOURCE_ID', $resourceId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? (int)$row['ID'] : null;
	}

	public function findBatchByType(
		int $instanceId,
		ManagedAgentResourceType $type,
		int $limit,
		int $afterId = 0,
	): array
	{
		if ($instanceId <= 0 || $limit <= 0)
		{
			return [];
		}

		// Keyset paging over ix_bp_ma_resource_cleanup: a removal pass never walks the table by offset.
		$query = ManagedAgentResourceTable::query()
			->setSelect(['*'])
			->where('INSTANCE_ID', $instanceId)
			->where('TYPE', $type->value)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
		;

		if ($afterId > 0)
		{
			$query->where('ID', '>', $afterId);
		}

		$ormCollection = $query->fetchCollection();
		if (!$ormCollection)
		{
			return [];
		}

		$resources = [];
		foreach ($ormCollection as $ormModel)
		{
			$resources[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $resources;
	}

	public function hasResourcesOfType(int $instanceId, ManagedAgentResourceType $type): bool
	{
		if ($instanceId <= 0)
		{
			return false;
		}

		return $this->exists([
			'INSTANCE_ID' => $instanceId,
			'TYPE' => $type->value,
		]);
	}

	public function hasResources(int $instanceId): bool
	{
		if ($instanceId <= 0)
		{
			return false;
		}

		return $this->exists(['INSTANCE_ID' => $instanceId]);
	}

	public function save(ManagedAgentResource $resource): ManagedAgentResource
	{
		try
		{
			$result = $this->mapper->convertToOrm($resource)->save();
		}
		catch (PersistenceException $exception)
		{
			throw $exception;
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException('Unable to save a managed agent resource', $exception);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to save a managed agent resource', null, $result->getErrors());
		}

		$this->rememberRegistryUse();

		if ($resource->isNew())
		{
			return $resource->withId((int)$result->getId());
		}

		return $resource;
	}

	public function delete(int $id): void
	{
		if ($id <= 0)
		{
			return;
		}

		try
		{
			$result = ManagedAgentResourceTable::delete($id);
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException('Unable to delete a managed agent resource', $exception);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete a managed agent resource', null, $result->getErrors());
		}
	}

	/**
	 * Whether this portal has ever owned a managed resource.
	 */
	private function isRegistryUsed(): bool
	{
		if ($this->registryUsedByThisRequest)
		{
			return true;
		}

		try
		{
			return Option::get(self::MODULE_ID, self::REGISTRY_USED_OPTION, 'N') === self::OPTION_YES;
		}
		catch (\Throwable)
		{
			// A flag that cannot be read is no proof of absence: pay the lookup instead of losing an ownership row.
			return true;
		}
	}

	/**
	 * Remembers that this portal owns a managed resource, which happens once in its life.
	 *
	 * The object is marked before the option is written, so that the invariant of
	 * {@see self::findIdByTypeAndResourceId()} holds even while the option cannot be stored at all. The stored
	 * flag is read before it is written, because a write of an unchanged value would still invalidate the option
	 * cache of the module and raise its event on every request that registers a resource.
	 */
	private function rememberRegistryUse(): void
	{
		if ($this->registryUsedByThisRequest)
		{
			return;
		}

		$this->registryUsedByThisRequest = true;

		try
		{
			if (Option::get(self::MODULE_ID, self::REGISTRY_USED_OPTION, 'N') !== self::OPTION_YES)
			{
				Option::set(self::MODULE_ID, self::REGISTRY_USED_OPTION, self::OPTION_YES);
			}
		}
		catch (\Throwable)
		{
			// A flag that could not be stored only means the next request reads the registry itself again.
		}
	}

	private function exists(array $conditions): bool
	{
		$query = ManagedAgentResourceTable::query()
			->setSelect(['ID'])
			->setLimit(1)
		;

		foreach ($conditions as $column => $value)
		{
			$query->where($column, $value);
		}

		return is_array($query->fetch());
	}
}
