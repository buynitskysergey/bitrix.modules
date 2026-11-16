<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * External access as a whole: the settings item of the knowledge-base actions menu with its public
 * tab, and the public `/pub/note/{token}` page with everything it mounts.
 *
 * One flag covers both sides on purpose: an entry point offered while the public page answers
 * "no such link" would hand the moderator a link that cannot be opened. The public component
 * checks it BEFORE the token is resolved, and when it is off the page answers exactly as it
 * does for a token that does not exist - an outside observer must not be able to tell "the
 * feature is off" from "no such link" or "access is closed".
 *
 * Switching it off is a kill-switch for the entry points, not for the data: the controller of
 * the settings keeps answering and existing links are not revoked.
 */
final class ExternalAccess extends AbstractFlag
{
}
