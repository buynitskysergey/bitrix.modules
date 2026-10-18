<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command\Import;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Note\Infrastructure\Agent\Import\ImportAgent;
use Bitrix\Note\Internal\Repository\ImportSessionRepository;

class CancelImportCommand extends AbstractCommand
{
	private int $sessionId;
	private ImportSessionRepository $sessionRepository;

	public function __construct(
		int $sessionId,
		?ImportSessionRepository $sessionRepository = null,
	)
	{
		$this->sessionId = $sessionId;
		$this->sessionRepository = $sessionRepository ?? new ImportSessionRepository();
	}

	protected function execute(): Result
	{
		$result = new Result();

		$session = $this->sessionRepository->getById($this->sessionId);
		if ($session === null)
		{
			$result->addError(new Error('Session not found'));

			return $result;
		}

		$this->removeAgent();
		$this->keepCancelledState();

		$this->sessionRepository->updateStatus($this->sessionId, 'cancelled');

		return $result;
	}

	/**
	 * What the stopped conversion leaves behind for the screen that reports it: the source it read, the
	 * bases it was asked for and the counters of what it managed to do. The state used to be deleted
	 * here, and with it went the only record of which bases the run had already created - the summary of
	 * a stop asked the server for them right after and was told about none, so it named nothing.
	 *
	 * The state is dropped by acknowledgeFinish, exactly as the one a run that reached its end leaves
	 * ({@see \Bitrix\Note\Infrastructure\Controller\ImportController::acknowledgeFinishAction}), which
	 * refuses a run still going and nothing else. The credentials of the source are not carried over -
	 * the same slim form the agent writes when it finishes
	 * ({@see \Bitrix\Note\Infrastructure\Agent\Import\ImportAgent::finishSession}).
	 */
	private function keepCancelledState(): void
	{
		$state = $this->readSessionState();
		if ($state === null)
		{
			return;
		}

		Option::set('main.stepper.note', $this->optionName(), serialize([
			'status' => 'cancelled',
			'sourceType' => $state['sourceType'] ?? null,
			'sourceUrl' => $state['sourceUrl'] ?? null,
			'collectionIds' => array_values((array)($state['collectionIds'] ?? [])),
			'collectionNames' => $state['collectionNames'] ?? [],
			// The base the stop interrupted is over as well, so its counters join those of the bases
			// converted before it: the screen of a run that is no longer going reads the global ones
			// ({@see \Bitrix\Note\Internal\Service\Import\ImportService::finalize}).
			'globalDoneCount' => self::sumCounter($state, 'globalDoneCount', 'doneCount'),
			'globalErrorCount' => self::sumCounter($state, 'globalErrorCount', 'errorCount'),
			'globalTotalAttachments' => self::sumCounter($state, 'globalTotalAttachments', 'totalAttachments'),
			'globalDoneAttachments' => self::sumCounter($state, 'globalDoneAttachments', 'doneAttachments'),
			'errorDetails' => $state['errorDetails'] ?? [],
			'errorMessage' => (string)($state['errorMessage'] ?? ''),
			// No step left to take. The agent is gone by the time this is written, and one that somehow
			// outlived the removal finds no handler for this step and stops
			// ({@see \Bitrix\Note\Internal\Service\Import\ImportService::processStep}) instead of
			// converting the bases named above all over again.
			'step' => '',
		]));
	}

	private function readSessionState(): ?array
	{
		$raw = Option::get('main.stepper.note', $this->optionName());
		if ($raw === '')
		{
			return null;
		}

		$state = unserialize($raw, ['allowed_classes' => false]);

		return is_array($state) ? $state : null;
	}

	private static function sumCounter(array $state, string $globalKey, string $currentKey): int
	{
		return (int)($state[$globalKey] ?? 0) + (int)($state[$currentKey] ?? 0);
	}

	private function optionName(): string
	{
		return ImportAgent::class . "({$this->sessionId})";
	}

	private function removeAgent(): void
	{
		$agentName = ImportAgent::class . '::execAgent(' . $this->sessionId . ');';

		Option::delete('main.stepper.note', ['name' => ImportAgent::class]);

		\CAgent::RemoveAgent($agentName, 'note');
	}
}
