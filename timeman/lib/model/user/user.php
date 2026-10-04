<?php
namespace Bitrix\Timeman\Model\User;

use Bitrix\Timeman\Helper\EntityCodesHelper;
use Bitrix\Timeman\Helper\UserHelper;

class User extends \Bitrix\Timeman\Model\User\EO_User
{
	private $isHead = false;
	private $utcOffset;
	private $timezoneName;

	public function obtainIsHeadOfDepartment()
	{
		return $this->isHead;
	}

	public function defineIsHeadOfDepartment($value)
	{
		$this->isHead = $value;
	}

	public function defineTimezoneName($timezoneName)
	{
		$this->timezoneName = $timezoneName;
	}

	public function obtainTimeZone()
	{
		if ($this->timezoneName === null)
		{
			return $this->getTimeZone();
		}
		return $this->timezoneName;
	}

	/**
	 * Returns the explicitly pinned zone name set via defineTimezoneName(), or null when nothing was
	 * pinned. An empty string is a legitimate pinned value (a synthetic historical offset that carries no
	 * meaningful IANA name). Callers that must distinguish "name was pinned" (use it verbatim) from
	 * "nothing pinned" (resolve/normalize instead of leaking the raw persisted TIME_ZONE) should prefer
	 * this over obtainTimeZone(), which conflates the two. Mirrors obtainPinnedUtcOffset().
	 *
	 * @return string|null
	 */
	public function obtainPinnedTimezoneName(): ?string
	{
		return $this->timezoneName;
	}

	public function defineUtcOffset($offset)
	{
		$this->utcOffset = $offset;
	}

	public function buildFormattedName()
	{
		return UserHelper::getInstance()->getFormattedName($this);
	}

	/**
	 * Returns the explicitly pinned (historical/snapshot) UTC offset, or null when no offset was
	 * pinned via defineUtcOffset(). Callers that need to distinguish «snapshot was set» from «nothing
	 * was set» should prefer this over obtainUtcOffset().
	 *
	 * @return int|null
	 */
	public function obtainPinnedUtcOffset()
	{
		return $this->utcOffset;
	}

	/**
	 * Historical accessor for the UTC offset of a recorded entry.
	 *
	 * Returns the frozen snapshot set via defineUtcOffset(). All consumers (recordformhelper,
	 * grid/templateparams, record.report/class) have been migrated to pin the offset explicitly
	 * (either the historical START_OFFSET or the date-aware viewer offset) before calling this
	 * method, so the legacy «as of now» fallback has been removed (P5 migration complete).
	 *
	 * The public contract is obtainPinnedUtcOffset(). This method exists for historical read-paths
	 * that rely on the (int) cast; new code should use obtainPinnedUtcOffset() instead.
	 *
	 * @internal
	 * @return int
	 */
	public function obtainUtcOffset()
	{
		return (int)$this->utcOffset;
	}

	public function obtainEntityCode()
	{
		return EntityCodesHelper::buildUserCode($this->getId());
	}
}
