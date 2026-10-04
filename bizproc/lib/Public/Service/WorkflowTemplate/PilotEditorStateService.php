<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\WorkflowTemplate;

use Bitrix\Bizproc\Internal\Config\PilotPublicationFeature;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\PilotVersionRepository;
use Bitrix\Bizproc\Internal\Service\Pilot\CommonRevisionWriter;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto\PilotEditorState;

/**
 * Everything an editor of templates has to know about the pilot of a template, given as one ready answer.
 * The editor lives in a module of its own, while the snapshot of the pilot, the rollout flag and the
 * fingerprint of the common scheme are internals of this one - so they are asked here and nowhere else.
 *
 * The whole state is built in a single pass on purpose: the fields of it stand on questions that overlap,
 * and asking them field by field is what the editor used to pay a query for.
 */
final class PilotEditorStateService
{
	public function __construct(
		private readonly PilotVersionRepository $pilotRepository = new PilotVersionRepository(),
		private readonly CommonRevisionWriter $revisionWriter = new CommonRevisionWriter(),
	)
	{
	}

	public function isFeatureEnabled(): bool
	{
		return PilotPublicationFeature::isEnabled();
	}

	/**
	 * Whether a pilot acts on the template, and the only question the operations over a live pilot ask
	 * before they act: the snapshot carries the whole executable scheme, and reading it to learn that the
	 * pilot is there is the heaviest way to learn it.
	 */
	public function hasPilot(int $templateId): bool
	{
		return $this->isFeatureEnabled() && $this->pilotRepository->hasPilot($templateId);
	}

	public function hasCommonVersion(int $templateId): bool
	{
		return $this->revisionWriter->hasCommonVersion($templateId);
	}

	public function markTemplateWithoutCommonVersion(int $templateId): bool
	{
		return $this->revisionWriter->markNoCommonVersion($templateId);
	}

	/**
	 * Neutral - no pilot, no common version, nothing frozen - while the feature is off, and that mirrors
	 * the visibility rule the whole feature stands on: nothing of the pilot is shown anywhere while it is
	 * disabled, even when a snapshot is still stored (switching the feature off deletes no data).
	 *
	 * The freeze of the settings is unfolded here instead of being asked of the gate that guards the write
	 * of them: its condition is the two questions this method has just answered, and the gate would ask
	 * both of them a second time - the snapshot with its whole scheme included.
	 */
	public function getEditorState(int $templateId): PilotEditorState
	{
		if (!$this->isFeatureEnabled())
		{
			return new PilotEditorState(
				hasPilot: false,
				pilotId: null,
				publishedBy: null,
				publishedAt: null,
				audienceCount: null,
				hasCommonVersion: false,
				settingsFrozen: false,
				executableFields: null,
			);
		}

		$pilot = $this->pilotRepository->getByTemplateId($templateId);
		$hasCommonVersion = $this->hasCommonVersion($templateId);

		return new PilotEditorState(
			hasPilot: $pilot !== null,
			pilotId: $pilot?->id,
			publishedBy: $pilot?->createdBy,
			publishedAt: $pilot?->created,
			audienceCount: $pilot !== null ? count($pilot->accessCodes) : null,
			hasCommonVersion: $hasCommonVersion,
			settingsFrozen: $pilot !== null && $hasCommonVersion,
			executableFields: $pilot?->executableFields,
		);
	}
}
