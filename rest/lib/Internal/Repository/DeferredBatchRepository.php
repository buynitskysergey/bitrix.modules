<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\DeferredBatch;
use Bitrix\Rest\Internal\Entity\DeferredBatch\Status;
use Bitrix\Rest\Internal\Model\DeferredBatchTable;
use Bitrix\Rest\Internal\Repository\Mapper\DeferredBatchMapper;

class DeferredBatchRepository implements RepositoryInterface
{
	public function __construct(private readonly DeferredBatchMapper $mapper = new DeferredBatchMapper())
	{
	}

	public function getById(mixed $id, array $select = ['*']): ?DeferredBatch
	{
		$row = DeferredBatchTable::query()
			->setSelect($select)
			->where('ID', (int)$id)
			->fetch();

		return $row !== false ? $this->mapper->convertFromArray($row) : null;
	}

	/**
	 * Atomically claims a batch for processing.
	 * Succeeds for pending rows, or for processing rows whose lease (UPDATED_AT) has expired.
	 */
	public function claimForProcessing(int $id, DateTime $leaseExpiredBefore): ?DeferredBatch
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = $sqlHelper->quote(DeferredBatchTable::getTableName());
		$now = $sqlHelper->convertToDbDateTime(new DateTime());
		$leaseThreshold = $sqlHelper->convertToDbDateTime($leaseExpiredBefore);
		$processing = $sqlHelper->forSql(Status::Processing->value);
		$pending = $sqlHelper->forSql(Status::Pending->value);

		$sql = "
			UPDATE {$tableName}
			SET STATUS = '{$processing}', UPDATED_AT = {$now}
			WHERE ID = " . (int)$id . "
				AND (
					STATUS = '{$pending}'
					OR (STATUS = '{$processing}' AND UPDATED_AT < {$leaseThreshold})
				)
		";

		$connection->queryExecute($sql);
		if ($connection->getAffectedRowsCount() <= 0)
		{
			return null;
		}

		return $this->getById($id);
	}

	/**
	 * Atomically deletes a batch that is not currently processing.
	 * Returns true when a row was deleted.
	 */
	public function deleteIfNotProcessing(int $id, int $userId): bool
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = $sqlHelper->quote(DeferredBatchTable::getTableName());
		$pending = $sqlHelper->forSql(Status::Pending->value);
		$done = $sqlHelper->forSql(Status::Done->value);
		$error = $sqlHelper->forSql(Status::Error->value);

		$sql = "
			DELETE FROM {$tableName}
			WHERE ID = " . (int)$id . "
				AND USER_ID = " . (int)$userId . "
				AND STATUS IN ('{$pending}', '{$done}', '{$error}')
		";

		$connection->queryExecute($sql);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Returns all deferred batches belonging to the given user, ordered by creation date descending.
	 *
	 * @return DeferredBatch[]
	 */
	public function getByUserId(int $userId): array
	{
		return $this->getByUserIdPaginated($userId);
	}

	/**
	 * return DeferredBatch[]
	 */
	public function getAllForDelete(array $statuses, DateTime $createdAt, int $limit = 1): array
	{
		$result = [];

		$query = DeferredBatchTable::query()
			->setSelect(['ID', 'RESULT_FILE_ID'])
			->whereIn('STATUS', $statuses)
			->where('CREATED_AT', '<', $createdAt)
			->setOrder([
				'STATUS' => 'ASC',
				'CREATED_AT' => 'ASC',
				'ID' => 'ASC',
			])
			->setLimit($limit);


		$dbResult = $query->exec();

		while ($row = $dbResult->fetch())
		{
			$result[] = $this->mapper->convertFromArray($row);
		}

		return $result;
	}

	/**
	 * Returns paginated deferred batches for the given user.
	 *
	 * @param int   $userId
	 * @param int   $limit  0 means no limit
	 * @param int   $offset
	 * @param array $order  e.g. ['ID' => 'DESC']
	 *
	 * @return DeferredBatch[]
	 */
	public function getByUserIdPaginated(int $userId, int $limit = 0, int $offset = 0, array $order = ['ID' => 'DESC']): array
	{
		$result = [];

		$query = DeferredBatchTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
			->setOrder($order);

		if ($limit > 0)
		{
			$query->setLimit($limit);
		}

		if ($offset > 0)
		{
			$query->setOffset($offset);
		}

		$dbResult = $query->exec();

		while ($row = $dbResult->fetch())
		{
			$result[] = $this->mapper->convertFromArray($row);
		}

		return $result;
	}

	/**
	 * @param DeferredBatch $entity
	 * @throws \Exception
	 */
	public function save(EntityInterface $entity): void
	{
		if ($entity->getId() === null)
		{
			$data = [
				'USER_ID' => $entity->getUserId(),
				'STATUS' => $entity->getStatus()?->value,
				'REQUEST_PARAMS' => $entity->getParams() ?? [],
				'COMMANDS' => $entity->getCommands() ?? [],
				'SCOPES' => $entity->getScopes() ?? [],
				'RESULT_FILE_ID' => $entity->getResultFileId(),
				'ERROR_MESSAGE' => $entity->getErrorMessage(),
			];

			$addResult = DeferredBatchTable::add($data);

			if ($addResult->isSuccess())
			{
				$entity->setId($addResult->getId());
			}
		}
		else
		{
			$updateResult = DeferredBatchTable::update($entity->getId(), [
				'STATUS' => $entity->getStatus()?->value,
				'RESULT_FILE_ID' => $entity->getResultFileId(),
				'ERROR_MESSAGE' => $entity->getErrorMessage(),
				'REQUEST_PARAMS' => $entity->getParams(),
			]);

			if (!$updateResult->isSuccess())
			{
				throw new \RuntimeException(implode('; ', $updateResult->getErrorMessages()));
			}
		}

	}

	public function delete(mixed $id): void
	{
		DeferredBatchTable::delete((int)$id);
	}
}
