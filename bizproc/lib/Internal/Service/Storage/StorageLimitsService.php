<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Storage;

use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\DB\SqlHelper;

final class StorageLimitsService
{
	public const BANNER_STATE_WARNING = 'warning';
	public const BANNER_STATE_BLOCKED = 'blocked';
	public const MAX_DISK_BUFFER_MB = 1024 * 1024;

	private const MAX_FIELDS_PER_STORAGE = 50;
	private const OPTION_DISK_BLOCK_BUFFER_MB = 'storage_disk_block_buffer';
	private const OPTION_DISK_WARNING_BUFFER_MB = 'storage_disk_warning_buffer';
	private const DEFAULT_DISK_BLOCK_BUFFER_MB = 1024;
	private const DEFAULT_DISK_WARNING_BUFFER_MB = 2048;

	private const STORAGE_TABLE_NAMES = [
		'b_bp_storage_record_field',
		'b_bp_storage_record_data',
		'b_bp_storage_field',
		'b_bp_storage_type',
	];

	private readonly RemainingDiskSpaceProviderInterface $remainingDiskSpaceProvider;
	private ?int $remainingDiskBytes = null;
	private bool $isRemainingDiskBytesResolved = false;

	public function __construct(RemainingDiskSpaceProviderInterface $remainingDiskSpaceProvider)
	{
		$this->remainingDiskSpaceProvider = $remainingDiskSpaceProvider;
	}

	public function getMaxFieldsPerStorage(): int
	{
		return self::MAX_FIELDS_PER_STORAGE;
	}

	public function getRemainingDiskBytes(): ?int
	{
		if (!$this->isRemainingDiskBytesResolved)
		{
			$this->remainingDiskBytes = $this->remainingDiskSpaceProvider->getRemainingBytes();
			$this->isRemainingDiskBytesResolved = true;
		}

		return $this->remainingDiskBytes;
	}

	public function shouldBlockWrite(): bool
	{
		$remaining = $this->getRemainingDiskBytes();

		return $remaining !== null && $remaining <= $this->getBlockBufferBytes();
	}

	public function getBannerState(): ?string
	{
		$remaining = $this->getRemainingDiskBytes();
		if ($remaining === null)
		{
			return null;
		}

		if ($remaining <= $this->getBlockBufferBytes())
		{
			return self::BANNER_STATE_BLOCKED;
		}

		if ($remaining <= $this->getWarningBufferBytes())
		{
			return self::BANNER_STATE_WARNING;
		}

		return null;
	}

	public static function isValidDiskBufferMb(string $sizeMb): bool
	{
		$sizeMb = trim($sizeMb);

		return preg_match('/^[0-9]+$/D', $sizeMb) === 1 && (int)$sizeMb <= self::MAX_DISK_BUFFER_MB;
	}

	private function getBlockBufferBytes(): int
	{
		return $this->getDiskBufferBytes(self::OPTION_DISK_BLOCK_BUFFER_MB, self::DEFAULT_DISK_BLOCK_BUFFER_MB);
	}

	private function getWarningBufferBytes(): int
	{
		return $this->getDiskBufferBytes(self::OPTION_DISK_WARNING_BUFFER_MB, self::DEFAULT_DISK_WARNING_BUFFER_MB);
	}

	private function getDiskBufferBytes(string $optionName, int $defaultMb): int
	{
		$optionValue = (string)Option::get('bizproc', $optionName, (string)$defaultMb);
		$sizeMb = self::isValidDiskBufferMb($optionValue) ? (int)$optionValue : $defaultMb;

		return $sizeMb * 1024 * 1024;
	}

	public function canAddField(int $storageId): bool
	{
		if ($storageId <= 0)
		{
			return true;
		}

		return StorageFieldTable::getFieldsCountByStorage($storageId) < $this->getMaxFieldsPerStorage();
	}

	/** Kept intentionally: disk limits use the portal quota, but storage table size is still needed for diagnostics. */
	public function getStorageTablesSizeBytes(): int
	{
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24'))
		{
			return 0;
		}

		$connection = Application::getConnection();
		if (!($connection instanceof MysqlCommonConnection))
		{
			return 0;
		}

		foreach (['INNODB_TABLESPACES', 'INNODB_SYS_TABLESPACES'] as $metadataTable)
		{
			$size = $this->fetchSizeBytes(
				$connection,
				$this->getMysqlPerTableSizeSql($connection, $metadataTable),
			);

			if ($size !== null)
			{
				return $size;
			}
		}

		return $this->fetchSizeBytes(
			$connection,
			$this->getMysqlInformationSchemaSizeSql($connection),
		) ?? 0;
	}

	private function getMysqlPerTableSizeSql(MysqlCommonConnection $connection, string $metadataTable): string
	{
		$sqlHelper = $connection->getSqlHelper();
		$tablesRef = $sqlHelper->quote('information_schema.tables');
		$metadataRef = $sqlHelper->quote('information_schema.' . $metadataTable);
		$schemaLiteral = "'" . $sqlHelper->forSql($connection->getDatabase()) . "'";
		$tablesList = $this->buildStorageTableNamesList($sqlHelper);

		return "
			SELECT COALESCE(SUM(
				CASE
					WHEN ts.NAME IS NOT NULL
						THEN COALESCE(ts.ALLOCATED_SIZE, ts.FILE_SIZE, 0)
					ELSE
						COALESCE(t.DATA_LENGTH, 0) + COALESCE(t.INDEX_LENGTH, 0)
				END
			), 0) AS SIZE
			FROM {$tablesRef} t
				LEFT JOIN {$metadataRef} ts
					ON ts.NAME = CONCAT(t.TABLE_SCHEMA, '/', t.TABLE_NAME)
			WHERE t.TABLE_SCHEMA = {$schemaLiteral}
				AND t.ENGINE = 'InnoDB'
				AND t.TABLE_NAME IN ({$tablesList})
		";
	}

	private function getMysqlInformationSchemaSizeSql(MysqlCommonConnection $connection): string
	{
		$sqlHelper = $connection->getSqlHelper();
		$tablesRef = $sqlHelper->quote('information_schema.tables');
		$schemaLiteral = "'" . $sqlHelper->forSql($connection->getDatabase()) . "'";
		$tablesList = $this->buildStorageTableNamesList($sqlHelper);

		return "
			SELECT COALESCE(SUM(
				COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)
			), 0) AS SIZE
			FROM {$tablesRef}
			WHERE TABLE_SCHEMA = {$schemaLiteral}
				AND ENGINE = 'InnoDB'
				AND TABLE_NAME IN ({$tablesList})
		";
	}

	private function buildStorageTableNamesList(SqlHelper $sqlHelper): string
	{
		$items = array_map(
			static fn(string $name) => "'" . $sqlHelper->forSql($name) . "'",
			self::STORAGE_TABLE_NAMES,
		);

		return implode(', ', $items);
	}

	private function fetchSizeBytes(MysqlCommonConnection $connection, string $sql): ?int
	{
		try
		{
			$value = $connection->queryScalar($sql);
		}
		catch (\Throwable)
		{
			return null;
		}

		return (int)($value ?? 0);
	}
}
