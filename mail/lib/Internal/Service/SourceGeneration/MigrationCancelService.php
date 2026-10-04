<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Main\Result;

final class MigrationCancelService
{
	public function __construct(
		private readonly MigrationService $migrationService = new MigrationService(),
	)
	{
	}

	public function step(int $mailboxId, string $operationId): Result
	{
		return $this->migrationService->cleanupCancelledOperation($mailboxId, $operationId);
	}
}
