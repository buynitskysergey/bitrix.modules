<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid;

use Bitrix\Bizproc\Internal\Config\Storage;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;
use Bitrix\Bizproc\Internal\Service\Feature\AiAgentsFeature;

/**
 * The single place that decides whether the user is shown the warning about agents already running
 * from a system template (ALG-01), and the only place that spends the personal right to see it.
 *
 * Nothing but a bool leaves this service: no counts, no names, no owners. The regional gate is not
 * repeated here - it is inherited from the controller family and cuts off the whole surface much
 * earlier.
 */
final class ExistingRunsWarningPolicy
{
	public function __construct(
		private readonly AiAgentsFeature $aiAgentsFeature,
		private readonly Storage $storage,
		private readonly AiAgentRepository $aiAgentRepository,
	) {}

	/**
	 * The checks run from the cheapest to the most expensive, and the right to show is spent last,
	 * only once everything else has lined up: a user who has already spent it never pays for the
	 * search for running agents, and a template without running agents never burns the right.
	 *
	 * The early "already spent" check is an optimization, not the one-shot guarantee - the
	 * guarantee comes from the atomic claim on the last line.
	 */
	public function shouldShowWarning(string $systemCode): bool
	{
		if (!$this->aiAgentsFeature->isAvailable())
		{
			return false;
		}

		if ($this->storage->isExistingRunsWarningSpent())
		{
			return false;
		}

		if (!$this->aiAgentRepository->hasActiveLaunchedCopy($systemCode))
		{
			return false;
		}

		return $this->storage->claimExistingRunsWarning();
	}
}
