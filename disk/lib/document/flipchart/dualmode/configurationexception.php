<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Main\SystemException;

/**
 * The new profile is unusable here: its address is not configured, the address fails the grammar of
 * ServiceAddress, or the cloud proxy is on, and the proxy signs every token for the old instance.
 *
 * Thrown before anything is done with the profile: before a board service client is built and before
 * the address reaches the page of the editor. Falling back to the old address would send a board of
 * the new profile to the instance that does not know it, so the failure has to be loud.
 */
class ConfigurationException extends SystemException
{
}
