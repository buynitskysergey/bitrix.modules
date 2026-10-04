<?php
declare(strict_types=1);

namespace Bitrix\Disk\FilePicker;

final class Context
{
	public function __construct(
		private readonly int $userId,
		private readonly ?SignedConfig $signedConfig = null,
	)
	{
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getSignedConfig(): ?SignedConfig
	{
		return $this->signedConfig;
	}
}
