<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\MailTemplate;

use InvalidArgumentException;

final readonly class TemplateReference
{
	public function __construct(
		private string $source,
		private int $id,
	)
	{
		if (trim($this->source) === '')
		{
			throw new InvalidArgumentException('Template source must not be empty.');
		}

		if ($this->id <= 0)
		{
			throw new InvalidArgumentException('Template id must be positive.');
		}
	}

	public function getSource(): string
	{
		return $this->source;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function equals(self $reference): bool
	{
		return $this->source === $reference->source && $this->id === $reference->id;
	}
}
