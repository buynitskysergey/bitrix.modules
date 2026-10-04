<?php

namespace Bitrix\Main\File;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Error;
use Bitrix\Main\FileTable;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class ContentReplacer
{
	// Existing replacement links already use this metadata key.
	private const OWNER_META_KEY = 'versionOwner';
	private const OBSOLETE_FILE_META_KEY = 'obsoleteFileId';

	/**
	 * Replaces the content resolved by a stable file ID and releases the superseded replacement.
	 * Must be called outside an external transaction; the owner is a trusted service identifier, not an access check.
	 *
	 * @param int $fileId Stable file ID.
	 * @param int $replacementId Prepared replacement ID, greater than the stable file ID.
	 * @param int|null $expectedReplacementId Expected current replacement, or null for the first assignment.
	 * @param string $owner Service identifier containing 1 to 100 letters, digits, dots, underscores or hyphens.
	 * @return Result Success data contains only the boolean changed flag.
	 */
	public function replace(int $fileId, int $replacementId, ?int $expectedReplacementId, string $owner): Result
	{
		if (
			$fileId <= 0
			|| $replacementId <= $fileId
			|| ($expectedReplacementId !== null && $expectedReplacementId <= 0)
			|| preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $owner) !== 1
		)
		{
			return $this->error('FILE_REPLACEMENT_INVALID', 'Invalid file replacement parameters.');
		}

		$pool = Application::getInstance()->getConnectionPool();
		$connection = Application::getConnection();
		$locked = false;
		$pool->useMasterOnly(true);

		try
		{
			$locked = $connection->lock('b_file', 0);
			if (!$locked)
			{
				$result = $this->error('FILE_REPLACEMENT_LOCKED', 'The file storage is locked.');
			}
			else
			{
				$result = $this->replaceLocked(
					$connection,
					$fileId,
					$replacementId,
					$expectedReplacementId,
					$owner,
				);
			}
		}
		catch (\Throwable)
		{
			$result = $this->error('FILE_REPLACEMENT_WRITE_FAILED', 'Could not replace the file content.');
		}
		finally
		{
			try
			{
				if ($locked && !$connection->unlock('b_file'))
				{
					$result = $this->error('FILE_REPLACEMENT_WRITE_FAILED', 'Could not release the file storage lock.');
				}
			}
			catch (\Throwable)
			{
				$result = $this->error('FILE_REPLACEMENT_WRITE_FAILED', 'Could not release the file storage lock.');
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
		}

		return $result;
	}

	private function replaceLocked(
		Connection $connection,
		int $fileId,
		int $replacementId,
		?int $expectedReplacementId,
		string $owner,
	): Result
	{
		$files = FileTable::query()
			->setSelect(['ID'])
			->whereIn('ID', [$fileId, $replacementId])
			->fetchAll()
		;
		if (count($files) !== 2)
		{
			return $this->error('FILE_REPLACEMENT_INVALID', 'Both files must exist.');
		}

		$current = $this->getReplacement($connection, $fileId);
		$metadata = $current === null ? [] : $this->getOwnedMetadata($current, $owner);
		if ($current !== null && $metadata === null)
		{
			return $this->error(
				'FILE_REPLACEMENT_CONFLICT',
				'The current file replacement has different ownership or metadata.',
			);
		}

		$currentId = $current === null ? null : (int)$current['VERSION_ID'];
		$obsoleteFileId = (int)($metadata[self::OBSOLETE_FILE_META_KEY] ?? 0);
		if (
			($currentId !== null && $currentId <= $fileId)
			|| (
				$obsoleteFileId > 0
				&& (
					$obsoleteFileId <= $fileId
					|| $obsoleteFileId === $currentId
					|| $obsoleteFileId === $replacementId
				)
			)
			|| !$this->isValidGraph($fileId, $replacementId, $currentId, $obsoleteFileId)
		)
		{
			return $this->error(
				'FILE_REPLACEMENT_INVALID',
				'File replacements must form independent one-to-one links.',
			);
		}

		if (
			$obsoleteFileId > 0
			&& !$this->finishPendingCleanup($connection, $fileId, $current, $metadata, $owner)
		)
		{
			return $this->error(
				'FILE_REPLACEMENT_WRITE_FAILED',
				'Could not finish cleanup of the superseded file replacement.',
			);
		}

		if ($currentId === $replacementId)
		{
			$this->cleanCaches($fileId);

			return (new Result())->setData(['changed' => false]);
		}
		if ($currentId !== $expectedReplacementId)
		{
			return $this->error('FILE_REPLACEMENT_CONFLICT', 'The current file replacement has changed.');
		}

		if ($current === null)
		{
			try
			{
				$insert = Internal\FileVersionTable::add([
					'ORIGINAL_ID' => $fileId,
					'VERSION_ID' => $replacementId,
					'META' => $this->getOwnerMetadata($owner),
				]);
			}
			catch (DuplicateEntryException)
			{
				return $this->resolveConcurrentWrite($connection, $fileId, $replacementId, $owner);
			}

			if (!$insert->isSuccess())
			{
				return $this->error('FILE_REPLACEMENT_WRITE_FAILED', 'Could not create the file replacement link.');
			}

			$this->cleanCaches($fileId);
		}
		else
		{
			$pendingMetadata = $this->getOwnerMetadata($owner) + [self::OBSOLETE_FILE_META_KEY => $currentId];
			if ($this->updateReplacement($connection, $fileId, $replacementId, $current, $pendingMetadata) !== 1)
			{
				return $this->resolveConcurrentWrite($connection, $fileId, $replacementId, $owner);
			}

			$this->cleanCaches($fileId);
			$current = [
				'VERSION_ID' => $replacementId,
				'META' => Json::encode($pendingMetadata),
			];
			if (!$this->finishPendingCleanup($connection, $fileId, $current, $pendingMetadata, $owner))
			{
				return $this->error(
					'FILE_REPLACEMENT_WRITE_FAILED',
					'Could not finish cleanup of the superseded file replacement.',
				);
			}
		}

		return (new Result())->setData(['changed' => true]);
	}

	protected function updateReplacement(
		Connection $connection,
		int $fileId,
		int $replacementId,
		array $current,
		array $metadata,
	): int
	{
		$helper = $connection->getSqlHelper();
		$metaField = $helper->quote('META');
		if ($connection->getType() === 'mysql')
		{
			$metaField = 'BINARY ' . $metaField;
		}

		$sql = 'UPDATE ' . $helper->quote(Internal\FileVersionTable::getTableName())
			. ' SET ' . $helper->quote('VERSION_ID') . ' = ' . $helper->convertToDbInteger($replacementId)
			. ', ' . $helper->quote('META') . ' = ' . $helper->convertToDbString(Json::encode($metadata))
			. ' WHERE ' . $helper->quote('ORIGINAL_ID') . ' = ' . $helper->convertToDbInteger($fileId)
			. ' AND ' . $helper->quote('VERSION_ID') . ' = ' . $helper->convertToDbInteger($current['VERSION_ID'])
			. ' AND ' . $metaField . ' = ' . $helper->convertToDbString($current['META']);
		$connection->queryExecute($sql);

		return (int)$connection->getAffectedRowsCount();
	}

	private function updateMetadata(Connection $connection, int $fileId, array $current, array $metadata): int
	{
		$helper = $connection->getSqlHelper();
		$metaField = $helper->quote('META');
		if ($connection->getType() === 'mysql')
		{
			$metaField = 'BINARY ' . $metaField;
		}

		$sql = 'UPDATE ' . $helper->quote(Internal\FileVersionTable::getTableName())
			. ' SET ' . $helper->quote('META') . ' = ' . $helper->convertToDbString(Json::encode($metadata))
			. ' WHERE ' . $helper->quote('ORIGINAL_ID') . ' = ' . $helper->convertToDbInteger($fileId)
			. ' AND ' . $helper->quote('VERSION_ID') . ' = ' . $helper->convertToDbInteger($current['VERSION_ID'])
			. ' AND ' . $metaField . ' = ' . $helper->convertToDbString($current['META']);
		$connection->queryExecute($sql);

		return (int)$connection->getAffectedRowsCount();
	}

	private function finishPendingCleanup(
		Connection $connection,
		int $fileId,
		array &$current,
		array $metadata,
		string $owner,
	): bool
	{
		$obsoleteFileId = (int)($metadata[self::OBSOLETE_FILE_META_KEY] ?? 0);
		if ($obsoleteFileId <= 0)
		{
			return true;
		}

		// A surviving duplicate row remains owned by its other references and must not be released twice.
		$this->deleteObsoleteFile($obsoleteFileId);
		$ownerMetadata = $this->getOwnerMetadata($owner);
		if ($this->updateMetadata($connection, $fileId, $current, $ownerMetadata) !== 1)
		{
			return false;
		}

		$current['META'] = Json::encode($ownerMetadata);
		$this->cleanCaches($fileId);

		return true;
	}

	private function getReplacement(Connection $connection, int $fileId): ?array
	{
		$helper = $connection->getSqlHelper();
		$sql = 'SELECT ' . $helper->quote('VERSION_ID') . ', ' . $helper->quote('META')
			. ' FROM ' . $helper->quote(Internal\FileVersionTable::getTableName())
			. ' WHERE ' . $helper->quote('ORIGINAL_ID') . ' = ' . $helper->convertToDbInteger($fileId);

		return $connection->query($sql)->fetch() ?: null;
	}

	private function getOwnedMetadata(array $replacement, string $owner): ?array
	{
		try
		{
			$metadata = Json::decode((string)$replacement['META']);
		}
		catch (ArgumentException)
		{
			return null;
		}

		if ($metadata === $this->getOwnerMetadata($owner))
		{
			return $metadata;
		}

		return is_array($metadata)
			&& array_keys($metadata) === [self::OWNER_META_KEY, self::OBSOLETE_FILE_META_KEY]
			&& $metadata[self::OWNER_META_KEY] === $owner
			&& is_int($metadata[self::OBSOLETE_FILE_META_KEY])
			&& $metadata[self::OBSOLETE_FILE_META_KEY] > 0
				? $metadata
				: null;
	}

	private function getOwnerMetadata(string $owner): array
	{
		return [self::OWNER_META_KEY => $owner];
	}

	private function isValidGraph(
		int $fileId,
		int $replacementId,
		?int $currentId,
		int $obsoleteFileId = 0,
	): bool
	{
		$ids = [$fileId, $replacementId];
		if ($currentId !== null)
		{
			$ids[] = $currentId;
		}
		if ($obsoleteFileId > 0)
		{
			$ids[] = $obsoleteFileId;
		}
		$ids = array_values(array_unique($ids));

		$otherOriginalIds = array_values(array_filter(
			$ids,
			static fn(int $id): bool => $id !== $fileId,
		));
		// META keeps MySQL on the ORIGINAL_ID primary key instead of a covering VERSION_ID scan.
		$originalConflict = Internal\FileVersionTable::query()
			->setSelect(['META'])
			->whereIn('ORIGINAL_ID', $otherOriginalIds)
			->setLimit(1)
			->fetch()
		;
		if ($originalConflict !== false)
		{
			return false;
		}

		$versionConflict = Internal\FileVersionTable::query()
			->setSelect(['ORIGINAL_ID'])
			->whereNot('ORIGINAL_ID', $fileId)
			->whereIn('VERSION_ID', $ids)
			->setLimit(1)
			->fetch()
		;

		return $versionConflict === false;
	}

	private function resolveConcurrentWrite(
		Connection $connection,
		int $fileId,
		int $replacementId,
		string $owner,
	): Result
	{
		$current = $this->getReplacement($connection, $fileId);
		$metadata = $current === null ? null : $this->getOwnedMetadata($current, $owner);
		$obsoleteFileId = (int)($metadata[self::OBSOLETE_FILE_META_KEY] ?? 0);
		if (
			$current !== null
			&& (int)$current['VERSION_ID'] === $replacementId
			&& $metadata !== null
			&& $this->isValidGraph($fileId, $replacementId, $replacementId, $obsoleteFileId)
		)
		{
			if (!$this->finishPendingCleanup($connection, $fileId, $current, $metadata, $owner))
			{
				return $this->error(
					'FILE_REPLACEMENT_WRITE_FAILED',
					'Could not finish cleanup of the superseded file replacement.',
				);
			}
			$this->cleanCaches($fileId);

			return (new Result())->setData(['changed' => false]);
		}

		return $this->error('FILE_REPLACEMENT_CONFLICT', 'The file replacement link was changed by another writer.');
	}

	protected function deleteObsoleteFile(int $fileId): void
	{
		\CFile::Delete($fileId);
	}

	private function cleanCaches(int $fileId): void
	{
		Internal\FileVersionTable::cleanCache();
		\CFile::CleanCache($fileId);
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
