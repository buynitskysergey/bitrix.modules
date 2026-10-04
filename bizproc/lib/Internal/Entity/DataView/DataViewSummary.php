<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\DataView;

/**
 * List projection of a data view: only the fields the views list needs. Kept separate from
 * {@see DataView} so listing does not read the TEXT columns (DEFINITION, DELETION_MARKS)
 * or decode their JSON.
 */
final class DataViewSummary
{
	public function __construct(
		public readonly int $id,
		public readonly int $storageTypeId,
		public readonly DataViewStatus $status,
	) {
	}
}
