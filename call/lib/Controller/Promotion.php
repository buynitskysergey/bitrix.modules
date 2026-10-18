<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Promotion\SyncTabBannerPromo;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\Scope;
use Bitrix\Main\Engine\Controller;

/**
 * @internal
 */
class Promotion extends Controller
{
	/**
	 * Takes the sync tab banner show for the current user: re-checks the right to show it and
	 * marks the promo as seen in the same call, so two tabs cannot both get the permission.
	 *
	 * Response shape:
	 * ```
	 * ['allowed' => bool] // true when this call took the show, false when it was already taken
	 * ```
	 */
	#[Scope(ActionFilter\Scope::AJAX)]
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function claimSyncTabBannerAction(): array
	{
		return ['allowed' => SyncTabBannerPromo::claim()];
	}
}
