<?php

namespace Bitrix\Sign\Contract\Chat\Message;

use Bitrix\Sign\Contract\Chat\Message;

/**
 * A card that covers several annulled signings at once and can say how many.
 *
 * Null means the card speaks of a single signing (or of an action whose size is
 * unknown), and the rendered text stays the one that existed before the counter.
 */
interface HasAnnulledCount extends Message
{
	public function getAnnulledCount(): ?int;
}
