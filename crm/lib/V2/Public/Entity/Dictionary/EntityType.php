<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Dictionary;

final readonly class EntityType
{
	public function __construct(
		private int $id,
		private string $code,
		private string $title,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function getTitle(): string
	{
		return $this->title;
	}
}
