<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Command\CustomTemplate;

enum OnTitleDuplicate: string
{
	case Reject = 'reject';
	case AutoSuffix = 'autoSuffix';
}
