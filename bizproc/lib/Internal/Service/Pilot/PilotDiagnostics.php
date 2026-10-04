<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * The register of the starts that could not be attributed to a manual start of an employee. The choice
 * of the version moved to the single point where an instance is created, and the risk moved with it:
 * instead of "a new start surface forgot the check" it is now "a start surface forgot the mark". The
 * second one is observable, and this is where it is observed from.
 *
 * A confirmed automatic start is not registered at all: the difference between "the mark is missing"
 * and "there is nothing to mark" is the difference between a record and no record.
 *
 * The channel is the tracking of the process, following the record of the applied restart branch: the
 * case belongs to a concrete run, has an initiator and a note of its own. The general event log is not
 * a substitute - it is bound to no instance. Type {@see \CBPTrackingType::Trigger} keeps the record out
 * of the history of the process the participants see, the same way the restart record is kept out.
 *
 * Everything here is best-effort: for the employee the start is an ordinary one, performed over the
 * common version, and a failure to write the diagnostics must not fail it.
 */
class PilotDiagnostics
{
	public const TRACKING_ACTION_NAME = 'PILOT_UNMARKED_START';

	public const REASON_NO_SURFACE = 'noSurface';
	public const REASON_NO_INITIATOR = 'noInitiator';

	private const LOGGER_ID = 'bizproc.pilot';

	/**
	 * The first two arguments are the ones the resolution rule passes; the run and its initiator are
	 * optional because the same rule answers the preparation of a start form, where no run exists yet.
	 *
	 * @param string|null $surface the identifier of the start surface, null when the mark was missing
	 * @param string|null $workflowId the run the start belongs to, known before the instance is built
	 */
	public function registerUnmarkedStart(
		int $templateId,
		?string $surface = null,
		?string $workflowId = null,
		int $initiatorId = 0,
	): void
	{
		$reason = ($surface === null || $surface === '') ? self::REASON_NO_SURFACE : self::REASON_NO_INITIATOR;
		$workflowId = trim((string)$workflowId);

		try
		{
			if ($workflowId === '')
			{
				$this->logUnaddressedCase($templateId, $surface, $reason);

				return;
			}

			$this->writeTracking($workflowId, $templateId, $surface, $reason, $initiatorId);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($templateId, $exception);
		}
	}

	protected function getTrackingService(): \CBPTrackingService
	{
		return \CBPRuntime::getRuntime()->getTrackingService();
	}

	private function writeTracking(
		string $workflowId,
		int $templateId,
		?string $surface,
		string $reason,
		int $initiatorId,
	): void
	{
		$trackingService = $this->getTrackingService();

		// the record must survive on the templates and editions where the tracking is otherwise off:
		// a diagnostics kept only where the log is full would report the coverage of the log
		$trackingService->setForcedMode($workflowId);

		$trackingService->write(
			$workflowId,
			\CBPTrackingType::Trigger,
			self::TRACKING_ACTION_NAME,
			\CBPActivityExecutionStatus::Closed,
			\CBPActivityExecutionResult::Succeeded,
			'',
			$this->noteOf($templateId, $surface, $reason),
			$initiatorId,
		);
	}

	/**
	 * Neither the scheme of the pilot nor anything about its version belongs here: the record names the
	 * template, the surface and the cause, and nothing else.
	 */
	private function noteOf(int $templateId, ?string $surface, string $reason): string
	{
		return sprintf(
			'reason=%s; templateId=%d; surface=%s',
			$reason,
			$templateId,
			$surface === null || $surface === '' ? '-' : $surface,
		);
	}

	/**
	 * A case with no run to address is kept in the log of the feature: the tracking is bound to an
	 * instance, and preparing a start form creates none.
	 */
	private function logUnaddressedCase(int $templateId, ?string $surface, string $reason): void
	{
		$this->getLogger()?->notice(
			'Bizproc pilot unmarked start for template {templateId}: {note}',
			[
				'templateId' => $templateId,
				'note' => $this->noteOf($templateId, $surface, $reason),
			],
		);
	}

	private function logFailure(int $templateId, \Throwable $exception): void
	{
		$this->getLogger()?->error(
			'Bizproc pilot diagnostics failed for template {templateId}: {message}',
			[
				'templateId' => $templateId,
				'message' => $exception->getMessage(),
			],
		);
	}

	/**
	 * Null while the logger is switched off in the registry: there is nothing to write the diagnostics
	 * to, so the caller skips it instead of feeding a NullLogger.
	 */
	private function getLogger(): ?LoggerInterface
	{
		return (new LoggerFactory(alwaysReturnLogger: false))->createById(self::LOGGER_ID);
	}
}
