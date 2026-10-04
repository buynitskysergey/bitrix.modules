<?php

namespace Bitrix\Crm\Activity\Provider\Tasks;

use Bitrix\Main\Result;

class TaskStateSyncResult extends Result
{
	private ?TaskActivityState $initialActivityState = null;
	/** @var int[] */
	private array $deletedCompletionEntryIds = [];

	public function getInitialActivityState(): ?TaskActivityState
	{
		return $this->initialActivityState;
	}

	public function setInitialActivityState(TaskActivityState $activityState): self
	{
		$this->initialActivityState = $activityState;

		return $this;
	}

	/**
	 * @return int[]
	 */
	public function getDeletedCompletionEntryIds(): array
	{
		return $this->deletedCompletionEntryIds;
	}

	/**
	 * @param int[] $entryIds
	 */
	public function addDeletedCompletionEntryIds(array $entryIds): self
	{
		$this->deletedCompletionEntryIds = array_values(array_unique([
			...$this->deletedCompletionEntryIds,
			...$entryIds,
		]));

		return $this;
	}
}
