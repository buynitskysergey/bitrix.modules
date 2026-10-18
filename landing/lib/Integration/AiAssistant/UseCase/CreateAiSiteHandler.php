<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Data\Site as CopilotSiteData;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Landing\Integration\AiAssistant\Dto\CreateAiSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiModuleUnavailableException;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSitesDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSitesUnavailableException;
use Bitrix\Landing\Integration\AiAssistant\Exception\BindingFinalizationException;
use Bitrix\Landing\Integration\AiAssistant\Exception\BindingUnavailableException;
use Bitrix\Landing\Integration\AiAssistant\Exception\GenerationFailedException;
use Bitrix\Landing\Integration\AiAssistant\Exception\ModerationBlockLimitException;
use Bitrix\Landing\Integration\AiAssistant\Exception\SiteCreateAccessDeniedException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingFinalizer;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site\Type;
use Bitrix\Main\Loader;

class CreateAiSiteHandler
{
	private const MODERATION_BLOCK_LIMIT_MESSAGE =
		'Too many topic moderation blocks for this draft. Please try again later with a different topic.';

	public function handle(CreateAiSiteDto $dto, int $userId): array
	{
		if ($dto->briefLines === [])
		{
			throw new \InvalidArgumentException('Brief lines are required to start site generation.');
		}

		if ($dto->bindingId <= 0)
		{
			throw new \InvalidArgumentException(AiSiteChatBindingContract::BINDING_REQUIRED_ERROR);
		}

		if (!$this->isAiSitesEnabled())
		{
			throw new AiSitesDisabledException();
		}

		$this->assertUserCanCreateSite($userId);

		$this->assertAiModuleAvailable();

		$this->assertSitesAiProductAvailable();

		$this->assertBindingCanStartGeneration($dto, $userId);

		$this->assertModerationBlockLimitNotReached($dto->bindingId, $userId);

		$generation = $this->createGeneration($dto, $userId);
		if (!$this->persistGenerationBeforeReservation($generation) || (int)($generation->getId() ?? 0) <= 0)
		{
			throw new \RuntimeException('Failed to persist site generation before binding reservation.');
		}

		if (!$this->reserveGenerationBinding($dto, $userId, $generation))
		{
			$this->discardGenerationBeforeStart($generation);

			throw new BindingUnavailableException(AiSiteChatBindingContract::BINDING_UNAVAILABLE_ERROR);
		}

		$generation->sendGenerationCreateEvent();

		$started = $generation->execute();
		$jobId = (string)($generation->getId() ?? '');
		if ($jobId === '')
		{
			throw new \RuntimeException('Failed to start site generation.');
		}

		return $this->buildStartResponse($generation, $jobId, $started);
	}

	/**
	 * Checks the release option AI sites are switched off by.
	 *
	 * Public on purpose, the same way assertUserCanCreateSite() is: a caller with side effects of
	 * its own (creating a free draft binding, for one) asks it before them, so that a call which is
	 * going to be refused is refused first and names the reason every other entry point names. The
	 * gate inside handle() stays the guarantee.
	 */
	public function assertAiSitesEnabled(): void
	{
		if (!$this->isAiSitesEnabled())
		{
			throw new AiSitesDisabledException();
		}
	}

	/**
	 * Checks the landing `create` right of the user the generation is started for.
	 *
	 * Public on purpose: a caller that has to refuse before its own side effects (creating a free
	 * draft binding, for one) calls it as a preflight, while the call inside handle() stays the
	 * guarantee. The rule itself lives here only, callers never reimplement it. The free draft is
	 * not what the preflight guards: getOrCreateFreeDraft() does not occupy the draft, and the
	 * reservation happens later inside handle() - after this very check.
	 *
	 * Trustworthiness precondition, checked by assertUserIsCurrentUser(): Rights::hasAdditionalRight()
	 * does not read the context user in every branch - Manager::isAdmin() reads $GLOBALS['USER'] and
	 * extra rights read the CRM rights of the context user. The answer is therefore trustworthy when
	 * $userId is the currently authenticated user, or when the caller has already substituted the
	 * whole user context the way BaseTool::runInUserContext() does.
	 *
	 * Second precondition: call it outside a section. The base section is pinned for the check,
	 * because an AI site is created with TYPE = 'PAGE' and belongs there, while the right is section
	 * scoped and a section left over from the caller would answer for that other section. Pinning it
	 * means clearing it: hasAdditionalRight() reads the entered section when given none, so there is
	 * no way to name the base one. No entry point of today enters a section before this call, so no
	 * caller is answered differently than it was before the check moved here.
	 *
	 * What is restored afterwards is the section id alone: setScope() is re-entered without the
	 * params it was originally given, and rolls back to no section at all when init() refuses to
	 * enter. A section entered by the caller therefore does not survive this check unchanged.
	 */
	public function assertUserCanCreateSite(int $userId): void
	{
		$this->assertUserIsCurrentUser($userId);

		$previousScopeId = Type::getCurrentScopeId();
		$previousRightsUserId = Rights::getContextUserId();

		try
		{
			Rights::setContextUserId($userId);
			Type::clearScope();

			$allowed = Rights::hasAdditionalRight(Rights::ADDITIONAL_RIGHTS['create']);
		}
		finally
		{
			if ($previousScopeId !== null)
			{
				Type::setScope($previousScopeId);
			}

			Rights::setContextUserId($previousRightsUserId);
		}

		if (!$allowed)
		{
			throw new SiteCreateAccessDeniedException();
		}
	}

	/**
	 * Keeps the answer of the right check from being collected for two different users: the branches
	 * that read the global user would answer "am I allowed" instead of "is he allowed". A mismatch is
	 * a caller error, not a refusal, and must not be answered with SiteCreateAccessDeniedException.
	 */
	private function assertUserIsCurrentUser(int $userId): void
	{
		$currentUserId = $this->getCurrentUserId();
		if ($userId !== $currentUserId)
		{
			throw new \InvalidArgumentException(
				'The landing create right is only checkable for the current user context,'
				. " asked for {$userId} while the current user is {$currentUserId}.",
			);
		}
	}

	/** The very source the global branches of the right check read. */
	protected function getCurrentUserId(): int
	{
		return Manager::getUserId();
	}

	/**
	 * Checks the ai module the generation runs on, and has to run before the product gate: the
	 * product availability chain (CopilotManager::isAvailable()) includes the ai module itself, so
	 * asked after it this refusal is unreachable - a portal without the module would answer with the
	 * product gate refusal instead of naming the outage.
	 *
	 * Public on purpose, the same way the other asserts of handle() are: a caller with side effects
	 * of its own asks it as a preflight in the same order, and the call inside handle() stays the
	 * guarantee.
	 */
	public function assertAiModuleAvailable(): void
	{
		if (!$this->includeAiModule())
		{
			throw new AiModuleUnavailableException();
		}
	}

	protected function includeAiModule(): bool
	{
		return Loader::includeModule('ai');
	}

	protected function isAiSitesEnabled(): bool
	{
		return CopilotManager::isAiSitesEnabled();
	}

	protected function checkSitesAiProductAvailability(): AiSiteChatAvailabilityResult
	{
		return $this->getAiSiteRuntimeAvailabilityGuard()->checkProductAvailability();
	}

	protected function getAiSiteRuntimeAvailabilityGuard(): AiSiteRuntimeAvailabilityGuard
	{
		return new AiSiteRuntimeAvailabilityGuard();
	}

	/**
	 * Checks the product gate of AI sites: the tariff and the tuning of Copilot, on top of the
	 * release option.
	 *
	 * Public on purpose, the same way assertAiSitesEnabled() and assertUserCanCreateSite() are: a
	 * caller with side effects of its own (creating a free draft binding, for one) asks it before
	 * them, so that a call which is going to be refused leaves nothing behind. The gate inside
	 * handle() stays the guarantee.
	 */
	public function assertSitesAiProductAvailable(): void
	{
		$availability = $this->checkSitesAiProductAvailability();
		if ($availability->isAvailable())
		{
			return;
		}

		throw new AiSitesUnavailableException($availability->getReasonCodeOrDefault('unknown'));
	}

	protected function createGeneration(CreateAiSiteDto $dto, int $userId): Generation
	{
		$generation = (new Generation())
			->setAuthorId($userId)
			->setScenario(new CreateAiSite())
			->setSiteData(new CopilotSiteData())
		;

		$input = [
			'input' => $dto->briefLines,
			AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD => $dto->bindingId,
		];

		CreateAiSiteState::setInput($generation, $input);
		CreateAiSiteState::setAiSiteBindingId($generation, $dto->bindingId);

		return $generation;
	}

	protected function assertBindingCanStartGeneration(CreateAiSiteDto $dto, int $userId): void
	{
		if (!$this->getAiSiteBindingService()->isFreeDraftForGeneration($dto->bindingId, $userId))
		{
			throw new BindingUnavailableException(AiSiteChatBindingContract::BINDING_UNAVAILABLE_ERROR);
		}
	}

	protected function assertModerationBlockLimitNotReached(int $bindingId, int $userId): void
	{
		if ($this->getAiSiteBindingService()->isModerationBlockLimitReached($bindingId, $userId))
		{
			throw new ModerationBlockLimitException(self::MODERATION_BLOCK_LIMIT_MESSAGE);
		}
	}

	protected function persistGenerationBeforeReservation(Generation $generation): bool
	{
		return $generation->persistBeforeStart(false);
	}

	protected function reserveGenerationBinding(CreateAiSiteDto $dto, int $userId, Generation $generation): bool
	{
		return $this->getAiSiteBindingService()->reserveDraftForGeneration(
			$dto->bindingId,
			$userId,
			(int)($generation->getId() ?? 0),
		);
	}

	protected function discardGenerationBeforeStart(Generation $generation): bool
	{
		return $generation->discardBeforeStart();
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

	private function buildStartResponse(Generation $generation, string $jobId, bool $started): array
	{
		if ($generation->isError())
		{
			$this->releaseDraftOnModerationBlock($generation);

			$limitError = AiSiteGenerationHelper::extractLimitError($generation);
			$creationJournal = AiSiteGenerationHelper::extractCreationJournal($generation);
			if ($limitError !== null || (!empty($creationJournal['recoverablePartial']) && (int)$creationJournal['siteId'] > 0))
			{
				$recoverablePartial = !empty($creationJournal['recoverablePartial']) && (int)$creationJournal['siteId'] > 0;

				return AiSiteGenerationHelper::buildStatusResponse(
					jobId: $jobId,
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
				(int)$jobId,
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
					jobId: $jobId,
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
				(int)$jobId,
			);
		}

		if (!$started)
		{
			$step = $generation->getStep() ?? 0;
			$message = $step > 0
				? 'Site generation is queued for retry.'
				: 'Site generation is queued.'
			;

			return AiSiteGenerationHelper::buildStatusResponse(
				jobId: $jobId,
				status: 'running',
				message: $message,
			);
		}

		return AiSiteGenerationHelper::buildStatusResponse(
			jobId: $jobId,
			status: 'running',
			message: 'Site generation is running.',
		);
	}
}
