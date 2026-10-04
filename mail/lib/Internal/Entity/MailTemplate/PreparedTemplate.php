<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\MailTemplate;

final readonly class PreparedTemplate
{
	public function __construct(
		private TemplateReference $reference,
		private string $subject,
		private string $bodyHtml,
	)
	{
	}

	public function getReference(): TemplateReference
	{
		return $this->reference;
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
