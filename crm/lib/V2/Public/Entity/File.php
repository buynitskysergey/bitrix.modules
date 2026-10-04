<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity;

class File
{
	private function __construct(
		private readonly ?int $id = null,
	)
	{
	}

	public static function fromId(int $id): self
	{
		return new self($id);
	}

	public function getId(): ?int
	{
		return $this->id;
	}
}
