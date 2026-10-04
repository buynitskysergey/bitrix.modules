<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\AiAgent;

use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstanceState;
use Bitrix\Bizproc\Internal\Model\AiAgent\ManagedAgentInstanceTable;
use Bitrix\Bizproc\Internal\Repository\Mapper\ManagedAgentInstanceMapper;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\DateTime;

class ManagedAgentInstanceRepository implements ManagedAgentInstanceRepositoryInterface
{
	/**
	 * Sorting field of the selection: whether the record carries a retry deadline at all.
	 */
	private const FIELD_RETRY_DEADLINE_SET = 'RETRY_DEADLINE_SET';

	public function __construct(
		private readonly ManagedAgentInstanceMapper $mapper = new ManagedAgentInstanceMapper(),
	)
	{
	}

	public function getById(int $id): ?ManagedAgentInstance
	{
		if ($id <= 0)
		{
			return null;
		}

		return $this->fetchOne(['ID' => $id]);
	}

	public function findByIdentityHash(string $identityHash): ?ManagedAgentInstance
	{
		if ($identityHash === '')
		{
			return null;
		}

		return $this->fetchOne(['IDENTITY_HASH' => $identityHash]);
	}

	/**
	 * Answers which instance owns the template, and nothing else.
	 *
	 * The barrier of the registry needs one answer more than this - it has to tell an unmanaged template from a
	 * template that is already gone - so it reads the template row and the instance in one joined query instead of
	 * calling this method. That leaves the method without a caller of the product today: it stays as the reading
	 * of the unique key ux_bp_ma_instance_template, which the cases and a diagnostic of a copy use.
	 *
	 * It is kept on purpose and is not dead: this is the entry point a copy deleted out of band needs, where the
	 * instance stays enabled and no background pass selects it, and the template id is the only thing such a
	 * discovery starts from.
	 */
	public function findByTemplateId(int $templateId): ?ManagedAgentInstance
	{
		if ($templateId <= 0)
		{
			return null;
		}

		return $this->fetchOne(['TEMPLATE_ID' => $templateId]);
	}

	public function findDueByStates(array $states, DateTime $dueAt, int $limit): array
	{
		$stateValues = $this->extractStateValues($states);
		if ($stateValues === [] || $limit <= 0)
		{
			return [];
		}

		$ormCollection = ManagedAgentInstanceTable::query()
			->setSelect(['*'])
			->whereIn('STATE', $stateValues)
			->where(
				Query::filter()
					->logic('or')
					->whereNull('NEXT_RETRY_AT')
					->where('NEXT_RETRY_AT', '<=', $dueAt)
			)
			// MySQL puts an empty deadline first and PostgreSQL puts it last, so the order says it explicitly:
			// a record without a deadline is due right now and never waits behind the scheduled ones.
			->registerRuntimeField(new ExpressionField(
				self::FIELD_RETRY_DEADLINE_SET,
				'CASE WHEN %s IS NULL THEN 0 ELSE 1 END',
				['NEXT_RETRY_AT'],
			))
			->setOrder([
				'STATE' => 'ASC',
				self::FIELD_RETRY_DEADLINE_SET => 'ASC',
				'NEXT_RETRY_AT' => 'ASC',
				'ID' => 'ASC',
			])
			->setLimit($limit)
			->fetchCollection()
		;

		if (!$ormCollection)
		{
			return [];
		}

		$instances = [];
		foreach ($ormCollection as $ormModel)
		{
			$instances[] = $this->mapper->convertFromOrm($ormModel);
		}

		return $instances;
	}

	public function lockById(int $id): ?ManagedAgentInstance
	{
		if ($id <= 0)
		{
			return null;
		}

		// The query builder cannot emit FOR UPDATE, and a plain reread after the lock may still answer from the
		// transaction snapshot, so the locking read itself has to bring the row back.
		$sql = (new SqlExpression(
			'SELECT * FROM ?# WHERE ?# = ?i FOR UPDATE',
			ManagedAgentInstanceTable::getTableName(),
			'ID',
			$id,
		))->compile();

		try
		{
			$row = Application::getConnection()->query($sql)->fetch();
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException('Unable to lock a managed agent instance', $exception);
		}

		if (!is_array($row))
		{
			return null;
		}

		return $this->mapper->convertFromOrm(ManagedAgentInstanceTable::wakeUpObject($row));
	}

	public function save(ManagedAgentInstance $instance): ManagedAgentInstance
	{
		try
		{
			$result = $this->mapper->convertToOrm($instance)->save();
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException('Unable to save a managed agent instance', $exception);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to save a managed agent instance', null, $result->getErrors());
		}

		if ($instance->isNew())
		{
			return $instance->withId((int)$result->getId());
		}

		return $instance;
	}

	public function compareAndSetState(
		int $id,
		array $expectedStates,
		ManagedAgentInstanceState $state,
		DateTime $updatedAt,
	): bool
	{
		$expectedValues = $this->extractStateValues($expectedStates);
		if ($id <= 0 || $expectedValues === [])
		{
			return false;
		}

		$affected = $this->executeUpdate(new SqlExpression(
			'UPDATE ?# SET ?# = ?s, ?# = ? WHERE ?# = ?i AND ?# IN (?@)',
			ManagedAgentInstanceTable::getTableName(),
			'STATE',
			$state->value,
			'UPDATED_AT',
			$updatedAt,
			'ID',
			$id,
			'STATE',
			$expectedValues,
		));

		if ($affected > 0)
		{
			return true;
		}

		// MySQL reports changed rows only, so an idempotent transition into the state the row already holds
		// affects nothing: the stored state has to confirm the postcondition instead.
		return in_array($state->value, $expectedValues, true) && $this->hasState($id, $state);
	}

	public function markEnabled(int $id, DateTime $updatedAt): bool
	{
		if ($id <= 0)
		{
			return false;
		}

		$affected = $this->executeUpdate(new SqlExpression(
			'UPDATE ?# SET ?# = ?s, ?# = 0, ?# = NULL, ?# = NULL, ?# = ? WHERE ?# = ?i AND ?# = ?s',
			ManagedAgentInstanceTable::getTableName(),
			'STATE',
			ManagedAgentInstanceState::Enabled->value,
			'RETRY_COUNT',
			'NEXT_RETRY_AT',
			'LAST_ERROR_CODE',
			'UPDATED_AT',
			$updatedAt,
			'ID',
			$id,
			'STATE',
			ManagedAgentInstanceState::Enabling->value,
		));

		return $affected > 0;
	}

	public function delete(int $id): void
	{
		if ($id <= 0)
		{
			return;
		}

		try
		{
			$result = ManagedAgentInstanceTable::delete($id);
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException('Unable to delete a managed agent instance', $exception);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete a managed agent instance', null, $result->getErrors());
		}
	}

	private function fetchOne(array $conditions): ?ManagedAgentInstance
	{
		$query = ManagedAgentInstanceTable::query()
			->setSelect(['*'])
			->setLimit(1)
		;

		foreach ($conditions as $column => $value)
		{
			$query->where($column, $value);
		}

		$ormModel = $query->fetchObject();

		return $ormModel === null ? null : $this->mapper->convertFromOrm($ormModel);
	}

	private function hasState(int $id, ManagedAgentInstanceState $state): bool
	{
		$row = ManagedAgentInstanceTable::query()
			->setSelect(['ID'])
			->where('ID', $id)
			->where('STATE', $state->value)
			->setLimit(1)
			->fetch()
		;

		return is_array($row);
	}

	/**
	 * @param ManagedAgentInstanceState[] $states
	 * @return string[] persisted tokens, because EnumField does not understand an enum object
	 */
	private function extractStateValues(array $states): array
	{
		$values = [];
		foreach ($states as $state)
		{
			if ($state instanceof ManagedAgentInstanceState)
			{
				$values[$state->value] = $state->value;
			}
		}

		return array_values($values);
	}

	/**
	 * @throws PersistenceException
	 */
	private function executeUpdate(SqlExpression $update): int
	{
		$connection = Application::getConnection();

		try
		{
			$connection->queryExecute($update->compile());
		}
		catch (\Throwable $exception)
		{
			throw new PersistenceException('Unable to change the state of a managed agent instance', $exception);
		}

		return (int)$connection->getAffectedRowsCount();
	}
}
