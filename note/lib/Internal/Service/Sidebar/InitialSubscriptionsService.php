<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Sidebar;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Note\Public\Provider\SubscriptionProvider;

/**
 * Whether the caller's sidebar may offer the notifications mode, answered in the same server pass
 * that paints the favorites block instead of by a request of its own.
 *
 * `false` - not an exception - when the answer cannot be obtained: the flag is optional, the mode is
 * merely unreachable without it, and the page itself must still open. Same reasoning as the
 * neighbouring initial services, whose failure costs a second request rather than the page.
 */
class InitialSubscriptionsService
{
	public function __construct(
		private readonly SubscriptionProvider $provider = new SubscriptionProvider(),
	) {}

	public function resolve(): bool
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return false;
		}

		try
		{
			return $this->provider->hasSubscriptions($userId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
