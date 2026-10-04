<?php

namespace Bitrix\Crm\RepeatSale\Segment;

use Bitrix\Main\Config\Option;

/**
 * Single access point for the "subscription has been observed on this portal" marker.
 *
 * The marker guards the one-time first-appearance enabling of AI scenarios: once a
 * subscription is seen (either through a real onSubscriptionRenew transition or via the
 * install/update migration on portals with a prior subscription), first-appearance never
 * fires again, so an ordinary renewal does not override the manual segment configuration.
 *
 * Both SubscriptionSegmentService (read/write on event-time) and the marker-init migration
 * (write on rollout) go through this class so the option name and module can never diverge.
 */
final class SubscriptionSeenMarker
{
	private const MODULE_ID = 'crm';
	public const OPTION_NAME = 'repeat_sale_subscription_seen';

	public function isSet(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, 'N') === 'Y';
	}

	public function set(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, 'Y');
	}
}
