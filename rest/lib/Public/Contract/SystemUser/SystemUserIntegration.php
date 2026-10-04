<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Contract\SystemUser;

final class SystemUserIntegration
{
	public const TYPE_APP = 'app';
	public const TYPE_WEBHOOK = 'webhook';

	private function __construct(
		private readonly string $type,
		private readonly int $id,
		private readonly string $title,
	)
	{
	}

	public static function forApplication(int $appId, string $title): self
	{
		return new self(self::TYPE_APP, $appId, $title);
	}

	public static function forWebhook(int $passwordId, string $title): self
	{
		return new self(self::TYPE_WEBHOOK, $passwordId, $title);
	}

	/**
	 * @return self::TYPE_*
	 */
	public function getType(): string
	{
		return $this->type;
	}

	/**
	 * AppTable.ID for an application, PasswordTable.ID for a webhook.
	 */
	public function getId(): int
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}
}
