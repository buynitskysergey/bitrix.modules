<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Agent\Import;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Update\Stepper;
use Bitrix\Note\Internal\Repository\ImportSessionRepository;
use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Public\Command\Import\ProcessImportStepCommand;
use Bitrix\Note\Public\Command\Import\ProcessImportStepResult;

class ImportAgent extends Stepper
{
	protected static $moduleId = 'note';

	public function execute(array &$option): bool
	{
		$sessionId = (int)($this->outerParams[0] ?? 0);
		if ($sessionId <= 0)
		{
			return self::FINISH_EXECUTION;
		}

		$sessionOption = $this->loadSessionOption($sessionId);
		if (empty($sessionOption))
		{
			return self::FINISH_EXECUTION;
		}

		try
		{
			$result = (new ProcessImportStepCommand($sessionOption))->run();
		}
		catch (\Throwable $e)
		{
			$step = $sessionOption['step'] ?? '?';
			$sourceType = $sessionOption['sourceType'] ?? '?';
			self::logError(sprintf(
				'ImportAgent exception (session=%d, source=%s, step=%s): %s in %s:%d%s%s',
				$sessionId,
				$sourceType,
				$step,
				$e->getMessage(),
				$e->getFile(),
				$e->getLine(),
				PHP_EOL,
				$e->getTraceAsString(),
			));
			$sessionOption['errorMessage'] = self::userFacingReason($e);
			$sessionOption['status'] = 'error';
			$this->finishSession($sessionId, 'error', $sessionOption);

			return self::FINISH_EXECUTION;
		}

		if (!$result->isSuccess() || !$result instanceof ProcessImportStepResult)
		{
			$errors = array_map(fn($e) => $e->getMessage(), $result->getErrors());
			self::logError(sprintf(
				'ImportAgent step failed (session=%d, source=%s, step=%s): %s',
				$sessionId,
				$sessionOption['sourceType'] ?? '?',
				$sessionOption['step'] ?? '?',
				implode('; ', $errors),
			));
			// The words of the errors stay in the log above: they are written for us and name classes,
			// queries and ids of the portal, while errorMessage is read on the screen of the initiator.
			$sessionOption['errorMessage'] = ImportLogger::unexpectedErrorReason();
			$sessionOption['status'] = 'error';
			$this->finishSession($sessionId, 'error', $sessionOption);

			return self::FINISH_EXECUTION;
		}

		$updatedOption = $result->getOption();

		if ($result->shouldContinue())
		{
			$this->saveSessionOption($sessionId, $updatedOption);

			return self::CONTINUE_EXECUTION;
		}

		$status = $updatedOption['status'] ?? 'error';
		$this->finishSession($sessionId, $status, $updatedOption);

		return self::FINISH_EXECUTION;
	}

	private function loadSessionOption(int $sessionId): array
	{
		$raw = Option::get('main.stepper.note', self::class . "({$sessionId})");
		if ($raw === '')
		{
			return [];
		}

		$data = unserialize($raw, ['allowed_classes' => false]);

		return is_array($data) ? $data : [];
	}

	private function saveSessionOption(int $sessionId, array $state): void
	{
		Option::set(
			'main.stepper.note',
			self::class . "({$sessionId})",
			serialize($state),
		);
	}

	private function deleteSessionOption(int $sessionId): void
	{
		Option::delete('main.stepper.note', ['name' => self::class . "({$sessionId})"]);
	}

	private function finishSession(int $sessionId, string $status, array $finalOption = []): void
	{
		$slim = [
			'status' => $status,
			'sourceType' => $finalOption['sourceType'] ?? null,
			'sourceUrl' => $finalOption['sourceUrl'] ?? null,
			'collectionIds' => $finalOption['collectionIds'] ?? [],
			'collectionNames' => $finalOption['collectionNames'] ?? [],
			'globalDoneCount' => $finalOption['globalDoneCount'] ?? 0,
			'globalErrorCount' => $finalOption['globalErrorCount'] ?? 0,
			'globalTotalAttachments' => $finalOption['globalTotalAttachments'] ?? 0,
			'globalDoneAttachments' => $finalOption['globalDoneAttachments'] ?? 0,
			'errorDetails' => $finalOption['errorDetails'] ?? [],
			'errorMessage' => $finalOption['errorMessage'] ?? '',
		];

		$this->saveSessionOption($sessionId, $slim);
		(new ImportSessionRepository())->updateStatus($sessionId, $status);
	}

	/**
	 * What a session stopped by an exception tells the user ([P6.T14]). The message of the exception
	 * itself never goes there: an error of the database layer carries the text of its query, and an
	 * error of any other layer carries internals of its own. The whole of it stays in the log written
	 * just above - the log does not get poorer, the answer to the user gets safe.
	 *
	 * The cause is unwrapped first: a command wraps whatever a step threw into an exception of its
	 * own ({@see \Bitrix\Main\Command\AbstractCommand::run()}), which names the command that failed
	 * and nothing about what went wrong, so the reason is decided by the cause and not by the wrapper.
	 *
	 * Always a phrase, never an empty string: an empty reason means "there is no reason" (API-03),
	 * and a session that stopped on an exception has one.
	 */
	private static function userFacingReason(\Throwable $e): string
	{
		return ImportLogger::resolveErrorReason($e->getPrevious() ?? $e);
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'IMPORT_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}
}
