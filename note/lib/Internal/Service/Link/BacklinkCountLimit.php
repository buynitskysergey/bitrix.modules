<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Link;

/**
 * Where the counter of incoming links stops counting: past this many sources the answer says "more
 * than this" instead of a number, and the badge of the widget shows the cap.
 *
 * The number lives here because both sides of the layer boundary go by it: the provider of the
 * ordinary circuit publishes it further as `BacklinkProvider::COUNT_CAP`, and PublicBacklinkService
 * of the public circuit counts to the same place without looking up into the layer above. One
 * declaration read from both - two of them with a note asking to keep them equal is how they stop
 * being equal, and here that would show as two different numbers on the same badge.
 */
final class BacklinkCountLimit
{
	public const CAP = 99;
}
