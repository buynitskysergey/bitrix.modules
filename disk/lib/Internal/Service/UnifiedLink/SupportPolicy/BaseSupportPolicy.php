<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\SupportPolicy;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Service\UnifiedLink\Configuration;
use Bitrix\Disk\Internal\Service\UnifiedLink\UniqueCodeBackfiller;

class BaseSupportPolicy implements SupportPolicy
{
	/**
	 * @param UniqueCodeBackfiller $uniqueCodeBackfiller
	 */
	public function __construct(
		protected readonly UniqueCodeBackfiller $uniqueCodeBackfiller,
	)
	{
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports(BaseObject $baseObject): bool
	{
		if (!Configuration::isEnabled())
		{
			return false;
		}

		if (!$baseObject instanceof File && !$baseObject instanceof Folder)
		{
			return false;
		}

		$uniqueCode = $baseObject->getUniqueCode();
		$isSetUniqueCode = (string)$uniqueCode !== '';

		if (!$isSetUniqueCode)
		{
			$isSetUniqueCode = $this->uniqueCodeBackfiller->backfillUniqueCode((int)$baseObject->getId());
		}

		return $isSetUniqueCode;
	}
}
