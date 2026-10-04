<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader\Value;

final readonly class FileMetadata
{
	public function __construct(
		private string $name,
	)
	{
	}

	public function getName(): string
	{
		return $this->name;
	}
}
