<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\MailTemplate;

use InvalidArgumentException;

final readonly class TemplateListItem
{
	public function __construct(
		private TemplateReference $reference,
		private string $title,
		private bool $canApply,
		private ?string $disabledReason = null,
		private string $subject = '',
	)
	{
		if ($this->canApply && $this->disabledReason !== null)
		{
			throw new InvalidArgumentException('Applicable template must not have a disabled reason.');
		}

		if (!$this->canApply && ($this->disabledReason === null || $this->disabledReason === ''))
		{
			throw new InvalidArgumentException('Disabled template must have a disabled reason.');
		}
	}

	public function getReference(): TemplateReference
	{
		return $this->reference;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getSubject(): string
	{
		return $this->subject;
	}

	public function canApply(): bool
	{
		return $this->canApply;
	}

	public function getDisabledReason(): ?string
	{
		return $this->disabledReason;
	}
}
