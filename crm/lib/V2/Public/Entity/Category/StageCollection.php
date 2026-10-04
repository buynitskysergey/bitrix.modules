<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Category;

use Bitrix\Main\Entity\EntityCollection;

/**
 * The stages of one category (pipeline), in the order they stand in.
 *
 * @extends EntityCollection<Stage>
 */
final class StageCollection extends EntityCollection
{
	public function first(): ?Stage
	{
		return $this->items[0] ?? null;
	}

	/** @return Stage[] */
	public function getAll(): array
	{
		return $this->items;
	}

	/**
	 * @internal Used by {@see \Bitrix\Crm\V2\Public\Command\Category\ReplaceStagesCommand} to report
	 * the stages the category ended up with: a group replacement answers with a set rather than with
	 * a stage, and the set that comes back is not the set that went in.
	 */
	public function internalReplaceAll(Stage ...$stages): void
	{
		$this->items = [];
		foreach ($stages as $stage)
		{
			$this->add($stage);
		}
	}

	protected static function getEntityClass(): string
	{
		return Stage::class;
	}
}
