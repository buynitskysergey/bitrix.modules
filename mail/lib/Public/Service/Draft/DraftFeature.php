<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Draft;

use Bitrix\Mail\Helper\Config\Feature;

/**
 * Whether internal drafts are available at all. A consumer that hosts its own compose form asks this
 * before it offers drafts, instead of reading the option or the feature gate of the mail module.
 */
final class DraftFeature
{
	public static function isAvailable(): bool
	{
		return Feature::isInternalDraftsAvailable();
	}
}
