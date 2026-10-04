<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage;

enum SignProviderCode: string
{
	case GOS_KEY = 'GOS_KEY';
	case GOS_KEY_LITE = 'GOS_KEY_LITE';
	case SES_RU = 'SES_RU';
	case SES_COM = 'SES_COM';
	case SES_RU_EXPRESS = 'SES_RU_EXPRESS';
}
