<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\PilotVersionRepository;

/**
 * Stops the pilot of a template whose live scheme has just been rewritten. A pilot publication never
 * writes the live scheme, so every write of it - publication for everyone, import, save from either
 * editor, update of a system template - is an unambiguous moment to stop the pilot, and the state
 * "a new common version with the previous pilot still running" becomes unreachable.
 *
 * The cascade ignores the rollout flag: it protects the integrity instead of showing the feature. With
 * the flag honoured here, a rewrite made while the feature is off would leave the pilot running over a
 * foreign scheme. The price is named in the ADR and accepted - while the feature is off the pilot stops
 * with no warning, because warning would reveal a pilot that must not be visible in that state.
 *
 * The scheme being replaced is not kept as a draft: the overwrite is deliberate, and the warning is
 * shown by the operation that has an interface of its own.
 *
 * The cascade runs on every write of a scheme on the portal, so it starts with the presence of a pilot:
 * while the portal has none at all, it costs no query.
 *
 * The stop is claimed and not assumed: the delete of the snapshot says whether this call is the one that
 * removed it, and only then is the portal-wide answer dropped.
 */
final class PilotCascadeHandler
{
	public function __construct(
		private readonly PilotVersionRepository $pilotRepository = new PilotVersionRepository(),
		private readonly PilotPresence $presence = new PilotPresence(),
	)
	{
	}

	/**
	 * The pilot mark of the template stays: the template keeps being hidden while it has no common
	 * version, and this write may well be the one that gives it one.
	 */
	public function onCommonSchemeWritten(int $templateId, bool $deferCacheInvalidation = false): void
	{
		if ($templateId <= 0 || !$this->presence->hasAnyPilot())
		{
			return;
		}

		if ($this->pilotRepository->deleteByTemplateId($templateId))
		{
			if (!$deferCacheInvalidation)
			{
				$this->presence->synchronize();
				$this->presence->invalidate();
			}
		}
	}
}
