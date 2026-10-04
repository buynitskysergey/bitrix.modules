<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotVersionResolver;
use Bitrix\Bizproc\Starter\Enum\ManualStartSurface;

/**
 * The parameters of the version that acts for the employee about to start the template. Every surface
 * building a start form reads them here: the set of the fields shown has to belong to the version that
 * will be executed for that employee, and a form assembled from the live row would ask a participant of a
 * pilot for the fields of somebody else's version.
 *
 * The name and the description of the template are not asked here on purpose: they are read from the live
 * row and are the same for everybody, the pilot does not version them.
 *
 * A read that names no surface gets the parameters of the live row and asks the resolver nothing. Such a
 * read is not a manual start of an employee, and putting the question anyway would count it as a start
 * that lost its mark.
 */
final class StartFormParameters
{
	public function __construct(
		private readonly PilotVersionResolver $versionResolver = new PilotVersionResolver(),
	)
	{
	}

	/**
	 * @param array $commonParameters PARAMETERS of the live template row
	 *
	 * @return array PARAMETERS of the version acting for this employee
	 */
	public function forInitiator(
		int $templateId,
		int $initiatorId,
		?ManualStartSurface $surface,
		array $commonParameters,
	): array
	{
		return $this->pilotParametersFor($templateId, $initiatorId, $surface) ?? $commonParameters;
	}

	/**
	 * Null when the common version acts for this employee: the caller keeps reading the live row exactly
	 * as it did before the feature, and a surface that never sees a pilot never changes its behaviour.
	 *
	 * @return array|null PARAMETERS of the pilot version
	 */
	public function pilotParametersFor(
		int $templateId,
		int $initiatorId,
		?ManualStartSurface $surface,
	): ?array
	{
		if ($surface === null || $templateId <= 0 || $initiatorId <= 0)
		{
			return null;
		}

		$choice = $this->versionResolver->resolve($templateId, $initiatorId, $surface->value);
		if ($choice->executableFields === null)
		{
			return null;
		}

		$pilotParameters = $choice->executableFields['PARAMETERS'] ?? [];

		return is_array($pilotParameters) ? $pilotParameters : [];
	}
}
