<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Model;

use Bitrix\Main\Access\AccessibleItem;

/**
 * Access item for a workflow template. The id is a {@see \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateTable} ID.
 * The controller never loads it implicitly ({@see \Bitrix\Bizproc\Internal\Access\AccessController::loadItem} returns
 * null); callers pass the item explicitly, mirroring the catalog store-item model.
 */
final class TemplateItem implements AccessibleItem
{
	private int $id;

	public function __construct(int $id)
	{
		$this->id = $id;
	}

	public static function createFromId(int $itemId): AccessibleItem
	{
		return new self($itemId);
	}

	public function getId(): int
	{
		return $this->id;
	}
}
