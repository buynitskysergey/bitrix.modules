<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

final readonly class Source
{
	public function __construct(
		private string $id,
		private ?string $name = null,
		private ?int $sort = null,
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
}
