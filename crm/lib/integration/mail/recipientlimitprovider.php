<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Mail;

use Bitrix\Mail\Public\Service\RecipientLimitService;
use Bitrix\Main\Loader;

final class RecipientLimitProvider
{
	private const FALLBACK_TOTAL = 10;

	public static function getTotal(): int
	{
		if (!Loader::includeModule('mail') || !class_exists(RecipientLimitService::class))
		{
			return self::FALLBACK_TOTAL;
		}

		$limit = (int)(RecipientLimitService::getRecipientLimits()['total'] ?? 0);

		return $limit > 0 ? $limit : self::FALLBACK_TOTAL;
	}
}
