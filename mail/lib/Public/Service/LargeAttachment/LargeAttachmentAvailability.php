<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\LargeAttachment;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\LicenseManager;

/**
 * Whether the large attachment scenario may be offered at all. A consumer that hosts its own compose form
 * asks this instead of reading the feature gate and the tariff of the mail module: the two answers are
 * different questions and the mail module owns both.
 */
final class LargeAttachmentAvailability
{
	/**
	 * Whether the scenario itself is released for this portal. Storing an attachment in the Disk costs the
	 * sender nothing extra; sending it as a service link is what the tariff decides about.
	 */
	public static function isDiskUploadAvailable(): bool
	{
		return Feature::isLargeAttachmentDiskUploadAvailable();
	}

	/**
	 * Whether the tariff of the portal allows the set of heavy attachments to be uploaded automatically.
	 */
	public static function isAutoUploadAllowedByTariff(): bool
	{
		return LicenseManager::isLargeAttachmentAutoUploadEnabled();
	}
}
