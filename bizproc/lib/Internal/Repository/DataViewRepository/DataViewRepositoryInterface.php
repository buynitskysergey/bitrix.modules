<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\DataViewRepository;

use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewFreshness;
use Bitrix\Bizproc\Internal\Entity\DataView\DataViewSummary;
use Bitrix\Main\Type\DateTime;

interface DataViewRepositoryInterface
{
	public function getByStorageTypeId(int $storageTypeId): ?DataView;

	/**
	 * Reads only the fields the read path needs to judge freshness, without loading
	 * and decoding the definition JSON.
	 */
	public function getFreshness(int $storageTypeId): ?DataViewFreshness;

	/**
	 * Reads only the fields the views list needs, without loading the TEXT columns
	 * (DEFINITION, DELETION_MARKS) or decoding their JSON.
	 *
	 * @return DataViewSummary[] ordered by id ascending
	 */
	public function getSummariesByOwner(int $templateId, string $activityName): array;

	public function getStorageTypeIds(): array;

	/**
	 * Batch counterpart of {@see getByStorageTypeId}: loads every requested view in one query.
	 *
	 * @param int[] $storageTypeIds
	 * @return array<int, DataView> found views keyed by storage type id
	 */
	public function getByStorageTypeIds(array $storageTypeIds): array;

	public function save(DataView $dataView): DataView;

	public function deleteByStorageTypeId(int $storageTypeId): void;

	public function markActual(DataView $dataView, DateTime $materializedAt, int $materializedBy): void;

	public function markBroken(DataView $dataView, string $errorText): void;

	public function appendDeletionMarks(DataView $dataView, array $identities): void;

	public function acquireCatalogLock(): bool;

	public function releaseCatalogLock(): void;

	public function acquireViewLock(int $storageTypeId): bool;

	public function releaseViewLock(int $storageTypeId): void;
}
