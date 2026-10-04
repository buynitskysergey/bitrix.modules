<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\WorkflowState;

use Bitrix\Main\Config\Option;

/**
 * Removal of a stuck workflow is irreversible, so it takes two passes of the daily sweep: this registry
 * carries the first sighting of a candidate over to the next pass. Whatever made the workflow look stuck
 * for a moment - a consumer race, an hour of queue outage - is gone by then and the candidate is not
 * confirmed.
 */
final class StuckPauseCandidateRegistry
{
	private const MODULE_ID = 'bizproc';
	private const OPTION_NAME = 'clear_stuck_pause_candidates';
	// Half of the interval of the daily agent: consecutive daily passes confirm a candidate, a sweep
	// restarted the same day (a reset cursor, a re-registered agent) does not.
	private const CONFIRMATION_MIN_AGE = 43200;
	public const SIGHTING_TTL = 259200;
	// Keeps the option within tens of kilobytes; a portal carrying more candidates than that clears them
	// over the following days, oldest sightings first.
	private const MAX_SIGHTINGS = 1000;

	/** @var array<string, int>|null */
	private ?array $sightings = null;

	private bool $isChanged = false;

	public function isConfirmed(string $workflowId): bool
	{
		$seenAt = $this->getSightings()[$workflowId] ?? null;

		return $seenAt !== null && $seenAt <= time() - self::CONFIRMATION_MIN_AGE;
	}

	public function remember(string $workflowId, ?int $seenAt = null): void
	{
		$seenAt ??= time();

		$knownSeenAt = $this->getSightings()[$workflowId] ?? null;
		if ($knownSeenAt !== null && $knownSeenAt <= $seenAt)
		{
			return;
		}

		$this->sightings[$workflowId] = $seenAt;
		$this->isChanged = true;
	}

	public function forget(string $workflowId): void
	{
		if (!isset($this->getSightings()[$workflowId]))
		{
			return;
		}

		unset($this->sightings[$workflowId]);
		$this->isChanged = true;
	}

	public function save(): void
	{
		$sightings = $this->getSightings();

		if (!$this->isChanged)
		{
			return;
		}

		asort($sightings);

		$encoded = json_encode(array_slice($sightings, 0, self::MAX_SIGHTINGS, true));

		Option::set(self::MODULE_ID, self::OPTION_NAME, $encoded === false ? '' : $encoded);
		$this->isChanged = false;
	}

	/**
	 * @return array<string, int>
	 */
	private function getSightings(): array
	{
		if ($this->sightings === null)
		{
			$this->sightings = $this->loadSightings();
		}

		return $this->sightings;
	}

	/**
	 * @return array<string, int>
	 */
	private function loadSightings(): array
	{
		$decoded = json_decode((string)Option::get(self::MODULE_ID, self::OPTION_NAME, ''), true);
		if (!is_array($decoded))
		{
			return [];
		}

		$expiredBefore = time() - self::SIGHTING_TTL;

		$sightings = [];
		foreach ($decoded as $workflowId => $seenAt)
		{
			if (is_string($workflowId) && is_int($seenAt) && $seenAt > $expiredBefore)
			{
				$sightings[$workflowId] = $seenAt;
			}
		}

		// Expired sightings are dropped here, so the pass that read them owes the option a rewrite:
		// without it a portal that once filled the registry keeps a full-size map of dead entries, and
		// every hit reading a bizproc option loads it along with the rest of the module.
		if (count($sightings) !== count($decoded))
		{
			$this->isChanged = true;
		}

		return $sightings;
	}
}
