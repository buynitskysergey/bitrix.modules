<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Descriptor;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteStatusDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSitesDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSitesUnavailableException;
use Bitrix\Landing\Integration\AiAssistant\Exception\BindingFinalizationException;
use Bitrix\Landing\Integration\AiAssistant\Exception\GenerationAccessDeniedException;
use Bitrix\Landing\Integration\AiAssistant\Exception\GenerationFailedException;
use Bitrix\Landing\Integration\AiAssistant\Exception\GenerationNotFoundException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingFinalizer;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;

class GetAiSiteStatusHandler
{
	/**
	 * Single answer for an unknown, foreign and off-scenario job.
	 *
	 * Generation ids are an autoincrement key, so telling the three apart would let a caller probe
	 * which ids exist and which scenario they run. The caller of a job of their own never sees it.
	 *
	 * The refusals stay typed apart for the callers that map them (REST answers all three with the
	 * same 404); only the wording is shared, and GenerationAccessDeniedException carries it too.
	 */
	private const MESSAGE_JOB_NOT_FOUND = GenerationNotFoundException::MESSAGE;

	public function handle(GetAiSiteStatusDto $dto, int $userId): array
	{
		$generationId = $this->parseGenerationId($dto->jobId);
		if ($generationId <= 0)
		{
			throw new GenerationNotFoundException(self::MESSAGE_JOB_NOT_FOUND);
		}

		// the release option is asked first on purpose: the product gate below reads the same option
		// too, but answers it as one availability reason among several, and the option deserves its
		// own refusal. Its branch inside the gate is therefore unreachable from here.
		if (!$this->isAiSitesEnabled())
		{
			throw new AiSitesDisabledException();
		}

		$this->assertSitesAiStatusReadAvailable();

		// Authorize the job on a light read before any payload is touched, so an unknown, foreign or
		// off-scenario job never reaches the heavy read. Skip a wait that has nothing left to wait
		// for: a job that finished or failed will not change any more. The payload is read once,
		// after the wait, so the status is fresh.
		$descriptor = $this->loadGenerationDescriptor($generationId, $userId);
		if ($dto->seconds > 0 && !$descriptor->isTerminal())
		{
			$this->waitForSeconds($dto->seconds);
		}

		$generation = $this->loadGeneration($generationId, $userId);

		if ($generation->isError())
		{
			$this->releaseDraftOnModerationBlock($generation);

			$limitError = AiSiteGenerationHelper::extractLimitError($generation);
			$creationJournal = AiSiteGenerationHelper::extractCreationJournal($generation);
			if ($limitError !== null || (!empty($creationJournal['recoverablePartial']) && (int)$creationJournal['siteId'] > 0))
			{
				$recoverablePartial = !empty($creationJournal['recoverablePartial']) && (int)$creationJournal['siteId'] > 0;

				return AiSiteGenerationHelper::buildStatusResponse(
					jobId: $dto->jobId,
					status: 'failed',
					message: $recoverablePartial
						? 'Site generation failed after creating a draft site.'
						: 'Site generation failed.',
					error: AiSiteGenerationHelper::resolveFailureMessage($generation),
					creationJournal: $creationJournal,
					limitError: $limitError,
				);
			}

			throw new GenerationFailedException(
				AiSiteGenerationHelper::resolveFailureMessage($generation),
				$generationId,
			);
		}

		[$siteId, $pageId] = AiSiteGenerationHelper::extractFinalIds($generation);
		if ($generation->isFinished())
		{
			if ($siteId > 0 && $pageId > 0)
			{
				if (!$this->finalizeCompletedGenerationBinding($generation))
				{
					throw new BindingFinalizationException();
				}

				$publication = AiSiteGenerationHelper::extractPublication($generation);
				$creationJournal = AiSiteGenerationHelper::extractCreationJournal($generation);
				$generationSummary = AiSiteGenerationHelper::extractGenerationSummary($generation);

				return AiSiteGenerationHelper::buildStatusResponse(
					jobId: $dto->jobId,
					status: 'completed',
					siteId: $siteId,
					pageId: $pageId,
					message: 'Site generation completed.',
					publication: $publication,
					creationJournal: $creationJournal,
					generationSummary: $generationSummary,
				);
			}

			throw new GenerationFailedException(
				'Site generation finished without final site identifiers.',
				$generationId,
			);
		}

		return AiSiteGenerationHelper::buildStatusResponse(
			jobId: $dto->jobId,
			status: 'running',
			message: 'Site is still generating. Call get_ai_site_status again immediately with the same jobId and seconds: 20. Site generation can take several minutes.',
		);
	}

	protected function waitForSeconds(int $seconds): void
	{
		sleep($seconds);
	}

	protected function isAiSitesEnabled(): bool
	{
		return CopilotManager::isAiSitesEnabled();
	}

	/**
	 * The product gate of the read-only slice: the same one the chat tools apply through
	 * AiSiteRuntimeAvailabilityToolGate, without the tariff and tuning gates that only guard a start.
	 *
	 * It lives here rather than in the tool gate alone because the tool gate is not on the path of
	 * every entry point any more: landing.aisite.status reaches this handler directly, and a portal
	 * the AI site product is closed for must answer the same way to both.
	 */
	protected function assertSitesAiStatusReadAvailable(): void
	{
		$availability = $this->checkSitesAiStatusReadAvailability();
		if ($availability->isAvailable())
		{
			return;
		}

		throw new AiSitesUnavailableException($availability->getReasonCodeOrDefault('unknown'));
	}

	protected function checkSitesAiStatusReadAvailability(): AiSiteChatAvailabilityResult
	{
		return $this->getAiSiteRuntimeAvailabilityGuard()->checkStatusReadProductAvailability();
	}

	protected function getAiSiteRuntimeAvailabilityGuard(): AiSiteRuntimeAvailabilityGuard
	{
		return new AiSiteRuntimeAvailabilityGuard();
	}

	private function parseGenerationId(string $jobId): int
	{
		if (!preg_match('/^[1-9][0-9]*$/', $jobId))
		{
			return 0;
		}

		return (int)$jobId;
	}

	/**
	 * Authorizes the job without reading its payload. This is the only authorization of the path.
	 *
	 * Runs on every call, so an unknown, foreign or off-scenario job is rejected before the worker is
	 * held and before the heavy read hydrates anything. Also reports whether the job is already over —
	 * by the finish mark on the generation, or by the error mark on a step. Both marks live outside
	 * the payload this read deliberately skips.
	 *
	 * @throws GenerationNotFoundException When the caller may not poll this job.
	 */
	protected function loadGenerationDescriptor(int $generationId, int $userId): Descriptor
	{
		$descriptor = Generation::findDescriptorById($generationId);
		if (
			$descriptor === null
			|| !$descriptor->isScenario(CreateAiSite::class)
			|| !$descriptor->isOwnedBy($userId)
		)
		{
			throw new GenerationNotFoundException(self::MESSAGE_JOB_NOT_FOUND);
		}

		return $descriptor;
	}

	/**
	 * Reads the payload needed to describe the status, once the descriptor has authorized the job.
	 *
	 * Narrow on purpose: this path never asks for the html blocks, and the field is heavy exactly
	 * while the client polls. The identity checks here are not the authorization — that already
	 * happened on the descriptor — but a guard against the row changing between the two reads, so
	 * they answer with the same single message.
	 *
	 * @throws GenerationNotFoundException|GenerationAccessDeniedException When the caller may not read this job.
	 */
	protected function loadGeneration(int $generationId, int $userId): Generation
	{
		$generation = new Generation();
		if (
			!$generation->initByIdWithoutHtmlBlocks($generationId)
			|| !$generation->getScenario() instanceof CreateAiSite
		)
		{
			throw new GenerationNotFoundException(self::MESSAGE_JOB_NOT_FOUND);
		}

		if (!$this->canAccessGeneration($generation, $userId))
		{
			throw new GenerationAccessDeniedException();
		}

		return $generation;
	}

	protected function finalizeCompletedGenerationBinding(Generation $generation): bool
	{
		if ($this->getAiSiteBindingFinalizer()->finalizeCompletedGeneration($generation))
		{
			return true;
		}

		return CreateAiSiteState::getAiSiteBindingId($generation) <= 0;
	}

	protected function getAiSiteBindingFinalizer(): AiSiteBindingFinalizer
	{
		return new AiSiteBindingFinalizer();
	}

	protected function getAiSiteBindingService(): AiSiteBindingService
	{
		return new AiSiteBindingService();
	}

	/**
	 * On a moderation block, free the reserved draft (so the same chat can retry) and bump the
	 * consecutive-block counter. No-op for technical failures and for non-moderation errors.
	 */
	protected function releaseDraftOnModerationBlock(Generation $generation): void
	{
		if (!AiSiteGenerationHelper::isModerationBlockError($generation))
		{
			return;
		}

		$this->getAiSiteBindingService()->registerModerationBlock(
			(int)($generation->getId() ?? 0),
			$generation->getAuthorId(),
		);
	}

	private function canAccessGeneration(Generation $generation, int $userId): bool
	{
		$authorId = $generation->getAuthorId();

		return $authorId > 0 && $authorId === $userId;
	}
}
