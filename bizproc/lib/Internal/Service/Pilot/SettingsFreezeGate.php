<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\PilotVersionRepository;
use Bitrix\Main\Localization\Loc;

/**
 * While a template runs a pilot over a common version of its own, the settings of the template stay as
 * they were: neither the composition of the settings form nor the values of the constants change until
 * the pilot is stopped or its version is published for everyone.
 *
 * The freeze is not caution, it follows from where the values live. They live in the live row and are
 * read lazily even by processes that are already running, they are deleted from disk without a way back
 * when a file constant falls out of the set, and they feed the schedule of the automatic starts, which
 * has no pilot-aware path. So a value cannot differ between the two versions, and while both versions
 * exist there is nothing to write it to.
 *
 * The condition is deliberately narrow: without a common version the pilot scheme is the only one, there
 * is nothing to protect, and the settings are edited as usual. That is what makes the first publication
 * straight to a pilot possible at all - the composition of the settings is declared by the scheme itself,
 * so before it is published there is nowhere to fill the values in.
 *
 * The rollout flag is not read here, for the same reason {@see PilotCascadeHandler} does not read it:
 * switching the feature off deletes no pilot, and letting the settings be edited while a pilot still runs
 * over them would change the values under the audience of that pilot.
 *
 * The gate stands on a path shared by the whole product, so it starts with the presence of a pilot on the
 * portal: while there is none, it costs no query.
 *
 * Whether the template has a common version is asked through {@see CommonRevisionWriter} and never of the
 * setting directly: a template written before the feature existed has no revision stored, and a raw read
 * would call it a template that was never published for everyone - releasing the freeze exactly where it
 * is needed.
 */
final class SettingsFreezeGate
{
	public const ERROR_SETTINGS_FROZEN = 'PILOT_SETTINGS_FROZEN';

	public function __construct(
		private readonly PilotVersionRepository $pilotRepository = new PilotVersionRepository(),
		private readonly CommonRevisionWriter $revisionWriter = new CommonRevisionWriter(),
		private readonly PilotPresence $presence = new PilotPresence(),
	)
	{
	}

	public function isFrozen(int $templateId): bool
	{
		if ($templateId <= 0 || !$this->presence->hasAnyPilot())
		{
			return false;
		}

		// the pilot goes first: it is the rarer of the two, and a template without one is left alone
		// before the settings of the template are read at all
		return $this->pilotRepository->getByTemplateId($templateId) !== null
			&& $this->revisionWriter->hasCommonVersion($templateId)
		;
	}

	/**
	 * Null while the settings are writable. The refusal is asked for before any effect of the write is
	 * produced: the files of the constants are deleted physically, and the write cannot be rolled back.
	 */
	public function findRefusal(int $templateId): ?Error
	{
		if (!$this->isFrozen($templateId))
		{
			return null;
		}

		return new Error(
			(string)Loc::getMessage('BIZPROC_PILOT_SETTINGS_FROZEN_ERROR'),
			self::ERROR_SETTINGS_FROZEN,
		);
	}
}
