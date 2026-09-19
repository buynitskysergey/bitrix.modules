<?php

namespace Bitrix\Sign\Result\Repository\Member;

use Bitrix\Sign\Result\SuccessResult;

/**
 * Outcome of an annulment write: the records this very call switched, named one by one
 * so that the caller can address its side effects by them. The count follows the set
 * and cannot name more records than it holds. An empty set is a success of its own and
 * not a failure - every addressed record already held the target state, so there was
 * nothing left to switch.
 */
class AnnulmentSwitchResult extends SuccessResult
{
	/** @var list<int> */
	public readonly array $switchedIds;
	public readonly int $switchedCount;

	/**
	 * @param list<int> $switchedIds
	 */
	public function __construct(array $switchedIds)
	{
		$this->switchedIds = array_values($switchedIds);
		$this->switchedCount = count($this->switchedIds);

		parent::__construct();
	}
}
