<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Type\CustomTemplate;

enum ReadableScopeKind
{
	case All;
	case None;
	case Targets;
	case Bindings;
}
