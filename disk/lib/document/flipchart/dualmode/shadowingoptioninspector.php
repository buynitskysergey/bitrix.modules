<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

/**
 * Finds site-scoped board options, which shadow the global ones.
 */
interface ShadowingOptionInspector
{
	/**
	 * @return string[] Names of the site-scoped rows found, empty when there are none.
	 */
	public function findShadowingSiteOptions(): array;
}
