<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Socialservices;

use Bitrix\Main\Config\Option;

/**
 * The id of this portal in Bitrix24.Network, stored in an option owned by the socialservices module.
 *
 * The owner may write the option within the same request, so the value is read from the platform on
 * every call and never memoized here. This is the sanctioned exception from reaching the options
 * only through Internal\Config\ModuleOptions: that access point serves this module alone.
 */
final class PortalNetworkId
{
	private const MODULE_ID = 'socialservices';
	private const OPTION_NAME = 'bitrix24net_id';
	private const GLOBAL_SITE_ID = '';

	/**
	 * Null when the portal has no id in the network: an absent option and an empty one mean the same.
	 */
	public function get(): ?string
	{
		$value = Option::getRealValue(self::MODULE_ID, self::OPTION_NAME, self::GLOBAL_SITE_ID);

		return $value === null || $value === '' ? null : $value;
	}
}
