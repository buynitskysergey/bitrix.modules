<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

final readonly class Stage
{
	public function __construct(
		private string $id,
		private ?string $name = null,
		private ?int $sort = null,
		private ?string $color = null,
		private ?string $semanticId = null,
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

	public function getSort(): ?int
	{
		return $this->sort;
	}

	public function getColor(): ?string
	{
		return $this->color;
	}

	public function getSemanticId(): ?string
	{
		return $this->semanticId;
	}
}
