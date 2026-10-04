<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

/**
 * The access codes of an employee, taken the only way they may be taken: through the platform, which
 * recalculates the composition of the departments lazily and caches the result itself. Reading
 * b_user_access directly skips that recalculation and answers with a stale set, so a pilot audience
 * would follow the org structure only until someone moved between departments.
 */
class UserAccessCodeProvider
{
	/**
	 * @return string[]
	 */
	public function getCodes(int $userId): array
	{
		return \CAccess::GetUserCodesArray($userId);
	}
}
