<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Category;

/**
 * A stage as the storage boundary hands it upwards.
 *
 * `stageId` is the whole stored identifier, already namespaced by category for entity types that
 * namespace it: `C7:NEW` for a Deal category, `DT1036_12:NEW` for a smart process one.
 *
 * `semantics` is always one of {@see \Bitrix\Crm\PhaseSemantics} `P`/`S`/`F`: the repository resolves
 * the `null` that process semantics is stored as.
 *
 * @internal
 */
final readonly class StageData
{
	public function __construct(
		public string $stageId,
		public int $categoryId,
		public string $name,
		public ?string $color,
		public string $semantics,
		public int $sort,
		public bool $isSystem,
	)
	{
	}
}
