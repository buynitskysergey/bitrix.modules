<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

final readonly class Webform
{
	public function __construct(
		private int $id,
		private ?string $name = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): ?string
	{
		return $this->name;
	}
}
