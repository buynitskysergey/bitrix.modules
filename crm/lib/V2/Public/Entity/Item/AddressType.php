<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

final readonly class AddressType
{
	public function __construct(
		private int $id,
		private string $name,
		private string $title,
	)
	{
		if (trim($this->title) === '')
		{
			throw new \InvalidArgumentException('Address type title must not be empty');
		}
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getTitle(): string
	{
		return $this->title;
	}
}
