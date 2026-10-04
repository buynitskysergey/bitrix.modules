<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market;

enum AppListType: string
{
	case Category = 'category';
	case Installed = 'installed';
	case Search = 'search';
}
