<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\MailTemplate;

final readonly class MailTemplate
{
	public function __construct(
		private int $id,
		private string $title,
		private int $scope,
		private int $entityTypeId,
		private string $subject = '',
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getSubject(): string
	{
		return $this->subject;
	}

	public function getScope(): int
	{
		return $this->scope;
	}

	public function getEntityTypeId(): int
	{
		return $this->entityTypeId;
	}

	public function isUniversal(): bool
	{
		return $this->entityTypeId === 0;
	}
}
