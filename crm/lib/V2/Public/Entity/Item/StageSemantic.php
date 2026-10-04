<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

/**
 * A dictionary entry of the stage field of an item: the value the field is stored with and the
 * caption that value is shown under. It describes a field of an item and nothing else - it is read
 * off an item, never chosen for one.
 *
 * The semantics of a stage as the domain of categories (pipelines) means it - process, success,
 * failure, without a storage value in sight - is
 * {@see \Bitrix\Crm\V2\Public\Entity\Category\StageSemantics}. That is the type a caller configuring
 * the stages of a category works with; this one stays what it always was, part of the field
 * dictionary of an item.
 */
final readonly class StageSemantic
{
	public function __construct(
		private string $id,
		private ?string $name = null,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getName(): ?string
	{
		return $this->name;
	}
}
