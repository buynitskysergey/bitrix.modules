<?php

namespace Bitrix\Crm\RepeatSale\Service;

use Bitrix\Crm\RepeatSale\Segment\SegmentItem;

final class Context
{
	private int $jobId;
	private int $segmentId;
	private ?SegmentItem $segmentItem = null;
	private ?SegmentItem $targetSegmentItem = null;

	public function getJobId(): int
	{
		return $this->jobId;
	}

	public function setJobId(int $jobId): self
	{
		$this->jobId = $jobId;

		return $this;
	}

	public function getSegmentId(): int
	{
		return $this->segmentId;
	}

	public function setSegmentId(int $segmentId): self
	{
		$this->segmentId = $segmentId;

		return $this;
	}

	public function getSegmentItem(): ?SegmentItem
	{
		return $this->segmentItem;
	}

	public function setSegmentItem(?SegmentItem $segmentItem): self
	{
		$this->segmentItem = $segmentItem;

		return $this;
	}

	/**
	 * Parent segment when the queue item segment is a child one, the queue item segment itself otherwise.
	 */
	public function getTargetSegmentItem(): ?SegmentItem
	{
		return $this->targetSegmentItem;
	}

	public function setTargetSegmentItem(?SegmentItem $targetSegmentItem): self
	{
		$this->targetSegmentItem = $targetSegmentItem;

		return $this;
	}
}
