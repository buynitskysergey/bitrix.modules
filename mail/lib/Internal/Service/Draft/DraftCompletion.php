<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Draft;

use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;

final class DraftCompletion
{
	public static function afterSuccessfulSend(
		int $userId,
		mixed $draftId,
		?string $contextType = null,
		?int $crmEntityTypeId = null,
		?int $crmEntityId = null,
		mixed $expectedRevision = null,
		?string $expectedClientId = null,
	): void
	{
		$draftId = is_numeric($draftId) ? (int)$draftId : 0;
		$expectedRevision = is_numeric($expectedRevision) ? (int)$expectedRevision : null;
		if ($userId <= 0 || $draftId <= 0)
		{
			return;
		}

		try
		{
			$result = (new DraftService())->complete(
				$userId,
				$draftId,
				$contextType,
				$crmEntityTypeId,
				$crmEntityId,
				$expectedRevision,
				$expectedClientId,
			);
			if ($result->isSuccess())
			{
				return;
			}

			$codes = array_map(
				static fn(Error $error): string => (string)$error->getCode(),
				$result->getErrors(),
			);
			self::log(
				$userId,
				$draftId,
				$codes,
				array_diff($codes, [
					DraftService::ERROR_ACCESS_DENIED,
					DraftService::ERROR_REVISION_CONFLICT,
				]) === [],
			);
		}
		catch (\Throwable $exception)
		{
			self::log($userId, $draftId, [$exception->getMessage()], false);
		}
	}

	private static function log(int $userId, int $draftId, array $errors, bool $isExpectedSkip): void
	{
		$logger = (new LoggerFactory())->createById('mail.Draft');
		$context = [
			'userId' => $userId,
			'draftId' => $draftId,
			'errors' => $errors,
		];

		if ($isExpectedSkip)
		{
			$logger?->warning('Draft completion skipped because its ownership or revision changed.', $context);

			return;
		}

		$logger?->critical('Draft completion failed after successful mail send.', $context);
	}
}
