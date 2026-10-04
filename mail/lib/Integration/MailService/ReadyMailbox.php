<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

final class ReadyMailbox
{
	public function __construct(
		private readonly string $operationId,
		private readonly int $mailboxId,
		private readonly string $email,
		private readonly array $imap,
		private readonly array $smtp,
		private readonly string $migratorService = '',
	)
	{
	}

	public function operationId(): string
	{
		return $this->operationId;
	}

	public function mailboxId(): int
	{
		return $this->mailboxId;
	}

	public function migratorService(): string
	{
		return $this->migratorService;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function connectionSnapshot(): array
	{
		return [
			'email' => $this->email,
			'imap' => $this->imap,
			'smtp' => $this->smtp,
		];
	}
}
