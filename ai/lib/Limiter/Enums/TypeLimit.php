<?php

declare(strict_types=1);

namespace Bitrix\AI\Limiter\Enums;

enum TypeLimit: string
{
	case PROMO = 'PROMO';
	case BAAS = 'BAAS';
	case SHARED_MONTHLY_POOL = 'SHARED_MONTHLY_POOL';
}
