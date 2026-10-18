<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Promo;

use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\UsersFloatingBannerFeature;
use Bitrix\Mobile\Menu\Promo\Dto\FloatingPromoData;
use Bitrix\Mobile\Provider\CommonUserDto;

final class FloatingPromoProvider
{
	public function __construct(private readonly CommonUserDto $user)
	{
	}

	/**
	 * Returns the floating promo payload of the menu response.
	 * Recalculated on every request: it never goes into MenuListCache.
	 *
	 * @return array{usersBannerEnabled: bool}
	 */
	public function getData(): array
	{
		return FloatingPromoData::make([
			'usersBannerEnabled' => $this->isVisible(),
		])->toArray();
	}

	/**
	 * The flag is a rollout switch, not an access check: the invite right is applied on the client.
	 */
	private function isVisible(): bool
	{
		if ($this->user->isExtranet || $this->user->isCollaber || $this->user->isGuest)
		{
			return false;
		}

		return Feature::isEnabled(UsersFloatingBannerFeature::class);
	}
}
