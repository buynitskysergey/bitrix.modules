<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\ItemIdentifier;

final readonly class StepContext
{
	public function __construct(
		private int $activityId,
		private int $userId,
		private string $scenarioName,
		private bool $isManualLaunch,
		private ?string $activityProvider = null,
		/**
		 * Explicit fill target for the manual launch path: the entity from whose timeline the user
		 * clicked the CoPilot button. When set (manual launch only), FillItemFields fills exactly this
		 * entity and the pipeline does NOT re-resolve the target via TargetResolver (ALG-01, single-target).
		 * Null for the auto path, where TargetResolver picks the priority Deal/Lead as before.
		 */
		private ?ItemIdentifier $manualTarget = null,
		/**
		 * Additional context for step creation.
		 * Currently only `assessmentSettingsId` is set in production (from CallQualityAssessment controller).
		 * Other keys (storageTypeId, storageElementId, screeningTarget, targetEntityTypeId, targetEntityId)
		 * are reserved for future AutoLauncher migration to PipelineExecutor.
		 */
		private array $extra = [],
	)
	{
	}

	public function getActivityId(): int
	{
		return $this->activityId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getScenarioName(): string
	{
		return $this->scenarioName;
	}

	public function isManualLaunch(): bool
	{
		return $this->isManualLaunch;
	}

	public function getActivityProvider(): ?string
	{
		return $this->activityProvider;
	}

	/**
	 * The entity clicked in the timeline for a manual launch, or null for the auto path.
	 * @see self::$manualTarget
	 */
	public function getManualTarget(): ?ItemIdentifier
	{
		return $this->manualTarget;
	}

	/**
	 * Single source of truth for the FillItemFields target (ALG-01, single-target).
	 * Manual launch: the clicked entity carried in the context — no re-resolve.
	 * Auto path (or manual without an explicit clicked target — defensive): the priority
	 * Deal/Lead resolved via TargetResolver, unchanged.
	 *
	 * Shared by StepFactory (operation creation) and StepResultResolver (reuse/short-circuit lookup)
	 * so both key on exactly the same target.
	 */
	public function resolveFillTarget(TargetResolver $targetResolver): ?ItemIdentifier
	{
		if ($this->isManualLaunch && $this->manualTarget !== null)
		{
			return $this->manualTarget;
		}

		return $targetResolver->findTarget($this->activityId);
	}

	public function getExtra(string $key, mixed $default = null): mixed
	{
		return $this->extra[$key] ?? $default;
	}

	public function withScenarioName(string $name): self
	{
		return new self(
			$this->activityId,
			$this->userId,
			$name,
			$this->isManualLaunch,
			$this->activityProvider,
			$this->manualTarget,
			$this->extra,
		);
	}

	public function withActivityProvider(?string $provider): self
	{
		return new self(
			$this->activityId,
			$this->userId,
			$this->scenarioName,
			$this->isManualLaunch,
			$provider,
			$this->manualTarget,
			$this->extra,
		);
	}

	public function withManualTarget(?ItemIdentifier $target): self
	{
		return new self(
			$this->activityId,
			$this->userId,
			$this->scenarioName,
			$this->isManualLaunch,
			$this->activityProvider,
			$target,
			$this->extra,
		);
	}

	public function withExtra(string $key, mixed $value): self
	{
		$extra = $this->extra;
		$extra[$key] = $value;

		return new self(
			$this->activityId,
			$this->userId,
			$this->scenarioName,
			$this->isManualLaunch,
			$this->activityProvider,
			$this->manualTarget,
			$extra,
		);
	}
}
