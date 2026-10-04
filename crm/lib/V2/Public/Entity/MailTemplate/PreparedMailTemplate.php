<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\MailTemplate;

final readonly class PreparedMailTemplate
{
	public function __construct(
		private int $id,
		private string $subject,
		private string $bodyHtml,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getSubject(): string
	{
		return $this->subject;
	}

	public function getBodyHtml(): string
	{
		return $this->bodyHtml;
	}
}
