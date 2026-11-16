<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Model\ExternalAccessCollectionTable;
use Bitrix\Note\Internal\Model\ExternalAccessTable;

/**
 * b_note_external_access plus its scope table b_note_external_access_collection.
 *
 * The repository is the isolation boundary of the external access feature, so its surface stays
 * narrow and every reader here is asked for by a named path of the circuit: enabling a record and
 * issuing a token never leave as separate operations either, otherwise a caller could produce an
 * enabled record without a token.
 *
 * Record shape returned by the readers:
 * @phpstan-type ExternalAccessRecord array{
 *     ID: int,
 *     COLLECTION_ID: int,
 *     TOKEN: string|null,
 *     IS_ENABLED: bool,
 *     ACCESS_LEVEL: int,
 *     PASSWORD_HASH: string|null,
 *     EXPIRES_AT: Date|null,
 *     CREATED_BY: int,
 *     UPDATED_BY: int,
 * }
 */
class ExternalAccessRepository
{
	private const RECORD_SELECT = [
		'ID' => 'ACCESS.ID',
		'COLLECTION_ID',
		'TOKEN' => 'ACCESS.TOKEN',
		'IS_ENABLED' => 'ACCESS.IS_ENABLED',
		'ACCESS_LEVEL' => 'ACCESS.ACCESS_LEVEL',
		'PASSWORD_HASH' => 'ACCESS.PASSWORD_HASH',
		'EXPIRES_AT' => 'ACCESS.EXPIRES_AT',
		'CREATED_BY' => 'ACCESS.CREATED_BY',
		'UPDATED_BY' => 'ACCESS.UPDATED_BY',
	];

	/**
	 * The record of one knowledge base, whatever its state: a draft that has never been enabled,
	 * an enabled one and an expired one all come back. Moderator actions and the cascades read it.
	 *
	 * @return array|null
	 */
	public function findByCollection(int $collectionId): ?array
	{
		if ($collectionId <= 0)
		{
			return null;
		}

		$row = ExternalAccessCollectionTable::query()
			->setSelect(self::RECORD_SELECT)
			->where('COLLECTION_ID', $collectionId)
			->setCacheTtl(0)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : $this->normalize($row);
	}

	/**
	 * The record behind a token, and only while the link actually works: enabled and not past its
	 * last day. Nothing is remembered anywhere - revoking access has to take effect at once, so every
	 * public request pays one read by the unique index instead of risking a stale answer.
	 *
	 * @return array|null
	 */
	public function findActiveByToken(string $token): ?array
	{
		if ($token === '')
		{
			return null;
		}

		$row = ExternalAccessCollectionTable::query()
			->setSelect(self::RECORD_SELECT)
			->where('ACCESS.TOKEN', $token)
			->where('ACCESS.IS_ENABLED', true)
			->where($this->notExpiredFilter())
			->setCacheTtl(0)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : $this->normalize($row);
	}

	/**
	 * The record behind its own id, and only while the link actually works. The paths that carry no
	 * token reach the record by the marks of the visitor session and still have to ask whether it
	 * opens anything. Read afresh every request for the reason findActiveByToken()
	 * is: a revoke has to take effect at once.
	 *
	 * The scope row is part of the question rather than an extra join: a record whose knowledge base
	 * has been deleted keeps its history but opens nothing any more.
	 *
	 * @return array|null
	 */
	public function findActiveById(int $accessId): ?array
	{
		if ($accessId <= 0)
		{
			return null;
		}

		$row = ExternalAccessCollectionTable::query()
			->setSelect(self::RECORD_SELECT)
			->where('ACCESS.ID', $accessId)
			->where('ACCESS.IS_ENABLED', true)
			->where($this->notExpiredFilter())
			->setCacheTtl(0)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : $this->normalize($row);
	}

	/**
	 * The stored password hash of one record, for the service that owns the marks of a visitor
	 * session. It is a reader of its own and not a field of the record above because the two answers
	 * "the link has no password" and "there is no such link" are different here and must not be
	 * collapsed - normalize() collapses them.
	 *
	 * @return string|null the stored hash ('' when the link has no password), null when there is no
	 *                     such record
	 */
	public function findPasswordHashById(int $accessId): ?string
	{
		if ($accessId <= 0)
		{
			return null;
		}

		$row = ExternalAccessTable::query()
			->setSelect(['PASSWORD_HASH'])
			->where('ID', $accessId)
			->setCacheTtl(0)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : (string)($row['PASSWORD_HASH'] ?? '');
	}

	/**
	 * "Not past its last day", in one copy: the two active readers must agree on what expired means.
	 */
	private function notExpiredFilter(): ConditionTree
	{
		return Query::filter()
			->logic('or')
			->whereNull('ACCESS.EXPIRES_AT')
			->where('ACCESS.EXPIRES_AT', '>=', new Date())
		;
	}

	/**
	 * Creates or updates the record of one knowledge base - one idempotent operation keyed by the
	 * collection, so a repeated call with the same values produces no second record. The unique
	 * index on COLLECTION_ID holds the "one link per knowledge base" rule underneath.
	 *
	 * $fields carries the columns of b_note_external_access only; the scope row is written here.
	 *
	 * @param array $fields
	 * @return array the stored record
	 * @throws PersistenceException when the record or its scope row was not written
	 */
	public function saveByCollection(int $collectionId, array $fields): array
	{
		$existing = $this->findByCollection($collectionId);
		$fields['UPDATED_AT'] = new DateTime();

		if ($existing === null)
		{
			$this->insertRecord($collectionId, $fields);
		}
		else
		{
			unset($fields['CREATED_BY'], $fields['CREATED_AT']);
			$result = ExternalAccessTable::update($existing['ID'], $fields);
			if (!$result->isSuccess())
			{
				throw new PersistenceException(
					'Unable to update note external access: ' . implode(', ', $result->getErrorMessages()),
				);
			}
		}

		return $this->findByCollection($collectionId) ?? [];
	}

	/**
	 * The record and its scope row are one write, so either both land or neither does. A record
	 * without a scope row is invisible to every reader here - they all join the scope table - while
	 * a scope row left behind holds the unique index of the knowledge base and turns every later
	 * attempt into a write error.
	 *
	 * A refused write is raised instead of being reported: the callers above store a state the
	 * moderator has just chosen, and a silent zero state would be read as a stored one.
	 *
	 * @param array $fields
	 * @throws PersistenceException
	 */
	private function insertRecord(int $collectionId, array $fields): void
	{
		$result = ExternalAccessTable::add($fields);
		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to add note external access: ' . implode(', ', $result->getErrorMessages()),
			);
		}

		$accessId = (int)$result->getId();

		try
		{
			$scopeResult = ExternalAccessCollectionTable::add([
				'ACCESS_ID' => $accessId,
				'COLLECTION_ID' => $collectionId,
			]);
			if (!$scopeResult->isSuccess())
			{
				throw new PersistenceException(
					'Unable to add note external access scope: ' . implode(', ', $scopeResult->getErrorMessages()),
				);
			}
		}
		catch (\Throwable $e)
		{
			// The row is removed instead of being rolled back: a caller may already be inside a
			// transaction of its own, and a nested rollback is an error of the core rather than the
			// error of the write that has to reach the caller.
			ExternalAccessTable::delete($accessId);

			throw $e;
		}
	}

	/**
	 * Puts out the link of one knowledge base. TOKEN and the settings survive: the record keeps its
	 * history, so a later re-enable is told apart from a first enable. UPDATED_BY is left alone on
	 * purpose - the cascade runs from archive and delete commands, and the author of the settings
	 * must not be overwritten by whoever happened to trigger them.
	 */
	public function disableByCollection(int $collectionId): void
	{
		$record = $this->findByCollection($collectionId);
		if ($record === null)
		{
			return;
		}

		$this->disableById($record['ID']);
	}

	/**
	 * The same, addressed by the record itself. The deletion cascade needs it: there the link is
	 * put out in the same transaction that drops the scope row, so looking the record up by its
	 * knowledge base would depend on the order of two writes instead of nothing.
	 */
	public function disableById(int $accessId): void
	{
		if ($accessId <= 0)
		{
			return;
		}

		ExternalAccessTable::update($accessId, [
			'IS_ENABLED' => false,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	/**
	 * Drops the knowledge base from the scope of its link. Used by the hard-delete cleanup, which
	 * puts the link out and removes the row pointing at a collection that no longer exists.
	 */
	public function removeCollectionFromScope(int $collectionId): void
	{
		if ($collectionId <= 0)
		{
			return;
		}

		ExternalAccessCollectionTable::deleteByFilter(['=COLLECTION_ID' => $collectionId]);
	}

	/**
	 * Removes a record that has never been enabled - TOKEN IS NULL and IS_ENABLED = 'N'. A record
	 * that worked at least once is kept: its token has been handed out, and the fact of it is what
	 * tells a draft from a revoked link.
	 *
	 * @return bool whether a draft was removed
	 */
	public function deleteDraftByCollection(int $collectionId): bool
	{
		$record = $this->findByCollection($collectionId);
		if ($record === null || $record['TOKEN'] !== null || $record['IS_ENABLED'])
		{
			return false;
		}

		ExternalAccessCollectionTable::deleteByFilter(['=ACCESS_ID' => $record['ID']]);
		ExternalAccessTable::delete($record['ID']);

		return true;
	}

	private function normalize(array $row): array
	{
		$token = $row['TOKEN'] ?? null;
		$passwordHash = $row['PASSWORD_HASH'] ?? null;

		return [
			'ID' => (int)$row['ID'],
			'COLLECTION_ID' => (int)$row['COLLECTION_ID'],
			'TOKEN' => ($token === null || $token === '') ? null : (string)$token,
			'IS_ENABLED' => $row['IS_ENABLED'] === true || $row['IS_ENABLED'] === 'Y',
			'ACCESS_LEVEL' => (int)$row['ACCESS_LEVEL'],
			'PASSWORD_HASH' => ($passwordHash === null || $passwordHash === '') ? null : (string)$passwordHash,
			'EXPIRES_AT' => $row['EXPIRES_AT'] instanceof Date ? $row['EXPIRES_AT'] : null,
			'CREATED_BY' => (int)$row['CREATED_BY'],
			'UPDATED_BY' => (int)$row['UPDATED_BY'],
		];
	}
}
