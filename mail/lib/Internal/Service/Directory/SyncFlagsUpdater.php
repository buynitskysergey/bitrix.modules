<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Directory;

use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final readonly class SyncFlagsUpdater
{
	/**
	 * @param array<int, array{dirMd5?: string, value?: int|string}> $dirs
	 * @param GenerationScope|null $scope Folders of one prepared generation; the active
	 *                                    generation of the mailbox by default.
	 */
	public function update(int $mailboxId, array $dirs, ?GenerationScope $scope = null): Result
	{
		$result = new Result();

		try
		{
			(new MailboxDirectoryHelper($mailboxId, null, $scope))->toggleSyncDirs($dirs);
		}
		catch (\Throwable $e)
		{
			$result->addError(new Error($e->getMessage(), 'MAIL_CLIENT_DIRS_SYNC_ERROR'));
		}

		return $result;
	}
}
