<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\WorkflowTemplate;

use Bitrix\Bizproc\Internal\Config\PilotPublicationFeature;
use Bitrix\Bizproc\Internal\Entity\WorkflowTemplate\AudienceMatch;
use Bitrix\Bizproc\Internal\Entity\WorkflowTemplate\VersionChoice;
use Bitrix\Bizproc\Internal\Exception\Pilot\AudienceUnavailableException;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\PilotVersionRepository;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotAudienceService;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotDiagnostics;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotPresence;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotTemplateSettings;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * The single answer to "which version of this template acts for this employee at this start". Neither a
 * start surface nor a start form chooses a version of its own: they only mark the start as a manual one
 * and name the initiator, and the answer is given here.
 *
 * Two kinds of callers ask it. The runtime asks before loading the scheme, and the preparation of the
 * start form asks before the run exists at all - the set of the parameters shown has to belong to the
 * version that will be started.
 *
 * The safe default of every branch is the common version. A start that could not be attributed to a
 * manual start of an employee runs the common scheme, and for that employee it is an ordinary start and
 * not an error. The same holds for a refusal of the audience service:
 * a platform that cannot answer must not fail a start.
 *
 * Until the portal has a pilot the audience rule costs nothing: the feature flag is a module option the
 * kernel has already loaded and the presence of a pilot is a cached portal-wide answer. The common
 * revision is still read so every started workflow keeps the revision of the scheme it executed.
 */
class PilotVersionResolver
{
	private const LOGGER_ID = 'bizproc.pilot';

	public function __construct(
		private readonly PilotVersionRepository $pilotRepository = new PilotVersionRepository(),
		private readonly PilotAudienceService $audienceService = new PilotAudienceService(),
		private readonly PilotPresence $presence = new PilotPresence(),
		private readonly PilotTemplateSettings $settings = new PilotTemplateSettings(),
		private readonly PilotDiagnostics $diagnostics = new PilotDiagnostics(),
	)
	{
	}

	/**
	 * @param string|null $surface the mark of a manual start, null when the start carries none
	 * @param bool $isNonManualStart a start that is known not to be a manual one - a child process of the
	 *     "Start a workflow" step, a debug run, a confirmed automatic start. Such a start asks no question
	 *     about the version and is no case for the diagnostics either: there is nothing to mark
	 *. names this branch isChildOrDebug.
	 * @param string|null $workflowId the run the start belongs to; the diagnostics is the only consumer,
	 *     and the preparation of a start form has no run to name
	 */
	public function resolve(
		int $templateId,
		int $initiatorId,
		?string $surface = null,
		bool $isNonManualStart = false,
		?string $workflowId = null,
	): VersionChoice
	{
		$surface = $this->markOf($surface);

		if ($isNonManualStart || !PilotPublicationFeature::isEnabled() || !$this->presence->hasAnyPilot())
		{
			return $this->commonChoice($templateId, AudienceMatch::NotApplicable, $surface);
		}

		if ($surface === null || $initiatorId <= 0)
		{
			$this->diagnostics->registerUnmarkedStart($templateId, $surface, $workflowId, max($initiatorId, 0));

			return $this->commonChoice($templateId, AudienceMatch::NotApplicable, $surface);
		}

		$membership = $this->findMembership($initiatorId, $templateId);
		if ($membership === null || $membership['match'] === AudienceMatch::NotApplicable)
		{
			return $this->commonChoice($templateId, AudienceMatch::NotApplicable, $surface);
		}

		if ($membership['match'] === AudienceMatch::NotMatched)
		{
			return $this->commonChoice($templateId, AudienceMatch::NotMatched, $surface);
		}

		$pilot = $this->pilotRepository->getRuntimeVersion($templateId, $membership['pilotId']);
		if ($pilot === null)
		{
			// the pilot was stopped between the membership query and the snapshot read
			return $this->commonChoice($templateId, AudienceMatch::NotApplicable, $surface);
		}

		return VersionChoice::pilotRuntime(
			$templateId,
			$pilot['executableFields'],
			$pilot['revision'],
			$surface,
		);
	}

	/**
	 * Null when the membership could not be established. The refusal is not "did not belong": counting it
	 * as one would raise the denominator of the coverage metric with starts nobody ever checked.
	 * The start itself goes on over the common version - a platform that did not answer must not fail it.
	 *
	 * @return array{match: AudienceMatch, pilotId: int|null}|null
	 */
	private function findMembership(int $initiatorId, int $templateId): ?array
	{
		try
		{
			return $this->audienceService->classifyTemplateWithPilotId($initiatorId, $templateId);
		}
		catch (AudienceUnavailableException $exception)
		{
			$this->logAudienceRefusal($templateId, $exception);

			return null;
		}
	}

	private function commonChoice(
		int $templateId,
		AudienceMatch $audienceMatch,
		?string $surface,
	): VersionChoice
	{
		return VersionChoice::common($audienceMatch, $this->commonRevisionOf($templateId), $surface);
	}

	/**
	 * A stored value is taken as it is, an empty one included, and the lazy fill of a template written
	 * before the feature is not triggered here: it hashes the whole activity tree, which is the cost of
	 * the start itself.
	 */
	private function commonRevisionOf(int $templateId): string
	{
		return $this->settings->getCommonSchemeRevision($templateId) ?? '';
	}

	private function markOf(?string $surface): ?string
	{
		$surface = trim((string)$surface);

		return $surface === '' ? null : $surface;
	}

	private function logAudienceRefusal(int $templateId, \Throwable $exception): void
	{
		$this->getLogger()?->warning(
			'Bizproc pilot audience is unavailable for template {templateId}: {message}',
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
