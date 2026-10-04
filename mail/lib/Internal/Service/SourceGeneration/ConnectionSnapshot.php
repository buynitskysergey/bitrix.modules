<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

final class ConnectionSnapshot
{
	public const STORAGE_FIELDS = [
		'EMAIL',
		'SERVICE_ID',
		'SERVICE_NAME',
		'SERVER',
		'PORT',
		'USE_TLS',
		'LOGIN',
		'PASSWORD',
		'SMTP_SERVER',
		'SMTP_PORT',
		'SMTP_PROTOCOL',
		'SMTP_LOGIN',
		'SMTP_PASSWORD',
		'SMTP_LIMIT',
	];

	public function __construct(
		private readonly string $email,
		private readonly array $imap,
		private readonly array $smtp,
	)
	{
	}

	public function email(): string
	{
		return $this->email;
	}

	public function imap(): array
	{
		return $this->imap;
	}

	public function smtp(): array
	{
		return $this->smtp;
	}

	public function toStorageFields(): array
	{
		$fields = [
			'EMAIL' => $this->email,
			'SERVICE_ID' => $this->imap['SERVICE_ID'],
			'SERVICE_NAME' => $this->imap['SERVICE_NAME'],
			'SERVER' => $this->imap['SERVER'],
			'PORT' => $this->imap['PORT'],
			'USE_TLS' => $this->imap['USE_TLS'],
			'LOGIN' => $this->imap['LOGIN'],
			'PASSWORD' => $this->imap['PASSWORD'],
			'SMTP_SERVER' => $this->smtp['SERVER'],
			'SMTP_PORT' => $this->smtp['PORT'],
			'SMTP_PROTOCOL' => $this->smtp['PROTOCOL'],
			'SMTP_LOGIN' => $this->smtp['LOGIN'],
			'SMTP_PASSWORD' => $this->smtp['PASSWORD'],
			'SMTP_LIMIT' => $this->smtp['LIMIT'],
		];

		return $fields;
	}

	public static function fromStorage(array $fields): self
	{
		return new self(
			email: (string)($fields['EMAIL'] ?? ''),
			imap: [
				'SERVICE_ID' => (int)($fields['SERVICE_ID'] ?? 0),
				'SERVICE_NAME' => isset($fields['SERVICE_NAME']) ? (string)$fields['SERVICE_NAME'] : null,
				'SERVER' => (string)($fields['SERVER'] ?? ''),
				'PORT' => (int)($fields['PORT'] ?? 0),
				'USE_TLS' => (string)($fields['USE_TLS'] ?? 'N'),
				'LOGIN' => (string)($fields['LOGIN'] ?? ''),
				'PASSWORD' => (string)($fields['PASSWORD'] ?? ''),
			],
			smtp: [
				'SERVER' => (string)($fields['SMTP_SERVER'] ?? ''),
				'PORT' => (int)($fields['SMTP_PORT'] ?? 0),
				'PROTOCOL' => (string)($fields['SMTP_PROTOCOL'] ?? ''),
				'LOGIN' => (string)($fields['SMTP_LOGIN'] ?? ''),
				'PASSWORD' => (string)($fields['SMTP_PASSWORD'] ?? ''),
				'LIMIT' => isset($fields['SMTP_LIMIT']) ? (int)$fields['SMTP_LIMIT'] : null,
			],
		);
	}

	public static function isCompleteStorage(array $fields): bool
	{
		foreach ([
			'EMAIL',
			'SERVICE_ID',
			'SERVER',
			'PORT',
			'USE_TLS',
			'LOGIN',
			'PASSWORD',
			'SMTP_SERVER',
			'SMTP_PORT',
			'SMTP_PROTOCOL',
			'SMTP_LOGIN',
			'SMTP_PASSWORD',
		] as $field)
		{
			if (!array_key_exists($field, $fields) || $fields[$field] === null)
			{
				return false;
			}
		}

		return true;
	}
}
