<?php

namespace Bitrix\MessageService\Internal\Repository;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\Collection;
use Bitrix\Main\Type\DateTime;
use Bitrix\MessageService\Internal\Entity\CustomTemplate;
use Bitrix\MessageService\Internal\Entity\CustomTemplateCollection;
use Bitrix\MessageService\Internal\Entity\CustomTemplateTable;
use Bitrix\MessageService\Internal\Repository\Mapper\CustomTemplateMapper;
use Bitrix\MessageService\Internal\ValueObject\CustomTemplateBinding;
use Bitrix\MessageService\Public\Type\CustomTemplate\ReadableScope;
use Bitrix\MessageService\Public\Type\CustomTemplate\ReadableScopeKind;

class CustomTemplateRepository implements RepositoryInterface
{
	private readonly CustomTemplateMapper $mapper;

	public function __construct(?CustomTemplateMapper $mapper = null)
	{
		$this->mapper = $mapper ?? new CustomTemplateMapper();
	}

	public function getById(int $id): ?CustomTemplate
	{
		$orm = CustomTemplateTable::getById($id)->fetchObject();

		return $orm ? $this->mapper->convertFromOrm($orm) : null;
	}

	/**
	 * Locate a template's binding without loading its BODY longtext: a narrow projection
	 * for callers (e.g. access prechecks) that only need ZONE/SCENE/TARGET_ID.
	 */
	public function getBindingById(int $id): ?CustomTemplateBinding
	{
		$row = CustomTemplateTable::query()
			->setSelect(['ZONE', 'SCENE', 'TARGET_ID'])
			->where('ID', $id)
			->setLimit(1)
			->fetch()
		;
		if (!$row)
		{
			return null;
		}

		return new CustomTemplateBinding(
			(string)$row['ZONE'],
			(string)$row['SCENE'],
			(string)$row['TARGET_ID'],
		);
	}

	/**
	 * @param int[] $ids
	 */
	public function getByIds(array $ids): CustomTemplateCollection
	{
		Collection::normalizeArrayValuesByInt($ids);

		if (empty($ids))
		{
			return new CustomTemplateCollection();
		}

		$collection = CustomTemplateTable::query()
			->setSelect(['*'])
			->whereIn('ID', $ids)
			->fetchCollection()
		;

		return $this->collect($collection);
	}

	public function save(CustomTemplate $entity): void
	{
		if ($entity->getId())
		{
			$entity->setDateModify(new DateTime());
		}
		$orm = $this->mapper->convertToOrm($entity);
		$result = $orm->save();
		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to save custom template: ' . implode('; ', $result->getErrorMessages())
			);
		}
		if (!$entity->getId())
		{
			$entity->setId((int)$result->getId());
		}
	}

	public function delete(int $id): void
	{
		$result = CustomTemplateTable::delete((int)$id);
		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to delete custom template: ' . implode('; ', $result->getErrorMessages())
			);
		}
	}

	public function getCurrentBindingForSelector(
		CustomTemplateBinding $binding,
		int $limit
	): CustomTemplateCollection
	{
		$query = CustomTemplateTable::query()
			->setSelect(['*'])
			->where('ZONE', $binding->zone)
			->where('SCENE', $binding->scene)
			->where('TARGET_ID', $binding->targetId)
			->addOrder('ID', 'DESC')
			->setLimit($limit)
		;

		return $this->collect($query->fetchCollection());
	}

	/**
	 * Top up the selector preload with zone-mates of the current binding, excluding
	 * the current binding itself (already loaded by {@see self::getCurrentBindingForSelector()}).
	 *
	 * ZONE stays a hard isolation boundary; only the scene/target pair is widened. The
	 * {@see ReadableScope} gates the top-up at the query level so unreadable bindings never
	 * consume the limit ahead of readable rows.
	 */
	public function topUpZoneForSelector(
		CustomTemplateBinding $binding,
		int $limit,
		?ReadableScope $scope = null,
	): CustomTemplateCollection
	{
		if ($limit <= 0)
		{
			return new CustomTemplateCollection();
		}

		$excludeCurrent = (new ConditionTree())
			->where('SCENE', $binding->scene)
			->where('TARGET_ID', $binding->targetId)
		;

		$query = CustomTemplateTable::query()
			->setSelect(['*'])
			->where('ZONE', $binding->zone)
			->whereNot($excludeCurrent)
		;
		if (!$this->applyScope($query, $scope))
		{
			return new CustomTemplateCollection();
		}
		$query
			->addOrder('ID', 'DESC')
			->setLimit($limit)
		;

		return $this->collect($query->fetchCollection());
	}

	/**
	 * Zone-wide selector search: matches the query against TITLE OR BODY within the
	 * binding's zone. ZONE remains a mandatory AND boundary so foreign zones never leak.
	 * The {@see ReadableScope} gates results at the query level so the limit is spent only
	 * on readable rows.
	 */
	public function searchZoneForSelector(
		CustomTemplateBinding $binding,
		string $like,
		int $limit,
		?ReadableScope $scope = null,
	): CustomTemplateCollection
	{
		if ($like === '' || $limit <= 0)
		{
			return new CustomTemplateCollection();
		}

		// TITLE and BODY store emoji as `:HEX8:` tokens (see CustomTemplateTable), so the LIKE
		// pattern is built from the encoded query to match. Encoding first also keeps the literal
		// ASCII: a raw emoji over the utf8mb3 connection would break against the utf8mb4 columns.
		$pattern = '%' . addcslashes(Emoji::encode($like), '\\%_') . '%';
		$textMatch = (new ConditionTree())
			->logic(ConditionTree::LOGIC_OR)
			->whereLike('TITLE', $pattern)
			->whereLike('BODY', $pattern)
		;

		$query = CustomTemplateTable::query()
			->setSelect(['*'])
			->where('ZONE', $binding->zone)
			->where($textMatch)
		;
		if (!$this->applyScope($query, $scope))
		{
			return new CustomTemplateCollection();
		}
		$query
			->addOrder('ID', 'DESC')
			->setLimit($limit)
		;

		return $this->collect($query->fetchCollection());
	}

	/**
	 * Constrain a zone-scoped query to the permission-side {@see ReadableScope}. `null` leaves
	 * the query ungated; `all()` adds no predicate; `none()` (or an empty target/binding set)
	 * matches nothing, signalled by a `false` return so the caller can short-circuit without
	 * running a query that yields no rows; `targets()` gates by TARGET_ID IN (...); `bindings()`
	 * gates by an OR group of (SCENE, TARGET_ID).
	 */
	private function applyScope(Query $query, ?ReadableScope $scope): bool
	{
		if ($scope === null || $scope->kind === ReadableScopeKind::All)
		{
			return true;
		}

		if ($scope->kind === ReadableScopeKind::None)
		{
			return false;
		}

		if ($scope->kind === ReadableScopeKind::Targets)
		{
			$targetIds = $scope->getTargetIds();
			if ($targetIds === [])
			{
				return false;
			}
			$query->whereIn('TARGET_ID', $targetIds);

			return true;
		}

		$bindings = $scope->getBindings();
		if ($bindings === [])
		{
			return false;
		}

		$allowed = (new ConditionTree())->logic(ConditionTree::LOGIC_OR);
		foreach ($bindings as $binding)
		{
			$allowed->where(
				(new ConditionTree())
					->where('SCENE', $binding->scene)
					->where('TARGET_ID', $binding->targetId)
			);
		}
		$query->where($allowed);

		return true;
	}

	public function getForGrid(
		int $limit,
		int $offset,
		?FilterInterface $filter = null,
		?array $sort = null,
	): CustomTemplateCollection
	{
		$query = CustomTemplateTable::query()
			->setSelect(['*'])
		;
		if ($filter !== null)
		{
			$query->where($filter->prepareFilter());
		}
		$query
			->setOrder($sort ?: ['ID' => 'DESC'])
			->setLimit($limit)
			->setOffset($offset)
		;

		return $this->collect($query->fetchCollection());
	}

	public function getCountForGrid(?FilterInterface $filter = null): int
	{
		$query = CustomTemplateTable::query();
		if ($filter !== null)
		{
			$query->where($filter->prepareFilter());
		}

		return (int)$query->queryCountTotal();
	}

	public function countInZone(string $zone): int
	{
		return CustomTemplateTable::getCount(['=ZONE' => $zone]);
	}

	public function deleteAllInZone(string $zone): void
	{
		$filter = ['=ZONE' => $zone];

		CustomTemplateTable::deleteByFilter($filter);
	}

	public function deleteAllByBinding(CustomTemplateBinding $binding): void
	{
		$filter = [
			'=ZONE' => $binding->zone,
			'=SCENE' => $binding->scene,
			'=TARGET_ID' => $binding->targetId,
		];

		CustomTemplateTable::deleteByFilter($filter);
	}

	public function deleteAllByZoneAndTarget(string $zone, string $targetId): void
	{
		$filter = [
			'=ZONE' => $zone,
			'=TARGET_ID' => $targetId,
		];

		CustomTemplateTable::deleteByFilter($filter);
	}

	public function deleteAllByZoneAndTargetPrefix(string $zone, string $targetPrefix): void
	{
		// Prefix-LIKE with the trailing % as a real wildcard; whereLike adds no surrounding
		// wildcards, so the colon-anchored prefix (e.g. '20:') never over-matches '200:'.
		$pattern = addcslashes($targetPrefix, '\\%_') . '%';

		$filter = (new ConditionTree())
			->where('ZONE', $zone)
			->whereLike('TARGET_ID', $pattern)
		;

		CustomTemplateTable::deleteByFilter($filter);
	}

	private function collect(mixed $ormItems): CustomTemplateCollection
	{
		$collection = new CustomTemplateCollection();
		if (!$ormItems || $ormItems->isEmpty())
		{
			return $collection;
		}
		foreach ($ormItems as $orm)
		{
			$collection->add($this->mapper->convertFromOrm($orm));
		}

		return $collection;
	}
}
